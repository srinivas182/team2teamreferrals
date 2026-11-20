<?php

/*
 * Going live on the demo server: Brevo for email, texting on or off, inviting teams from a list, and resetting the
 * data for launch.
 */

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Platform\Providers\ApplyProviderSettings;
use App\Domains\Platform\Settings\Settings;
use App\Support\Sms\Texting;

function kitAdmin(): User
{
    config(['app.staff_two_factor' => false]);
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole(Permissions::SUPER_ADMIN);

    return $u;
}

it('sends email through Brevo once its SMTP login and key are saved', function () {
    $admin = kitAdmin();
    $this->actingAs($admin)->put('/admin/system/email', ['driver' => 'brevo', 'from_address' => 'hello@example.com', 'from_name' => 'T2T', 'fields' => ['login' => '9a1b2c001@smtp-brevo.com', 'smtp_key' => 'xsmtpsib-secret']])
        ->assertSessionHasNoErrors();
    app(ApplyProviderSettings::class)();
    expect(config('mail.default'))->toBe('brevo')
        ->and(config('mail.mailers.brevo'))->toMatchArray(['transport' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'username' => '9a1b2c001@smtp-brevo.com', 'password' => 'xsmtpsib-secret']);
    // The key is kept as a secret: never shown again.
    $this->actingAs($admin)->get('/admin/system/email')->assertInertia(fn ($p) => $p->where('email.drivers', fn ($d) => collect($d)->firstWhere('key', 'brevo')['fields'][1]['value'] === null));
});

it('texts only while texting is on and a provider is set up; off, sign-up skips the mobile code', function () {
    expect(Texting::on())->toBeFalse(); // no provider
    $member = User::factory()->phoneUnverified()->create();
    $this->actingAs($member)->get('/app')->assertOk(); // not sent to /verify-phone
    $this->get('/verify-phone')->assertRedirect('/app');
    $this->post('/logout');
    $this->get('/register')->assertInertia(fn ($p) => $p->where('texting', false));

    $admin = kitAdmin();
    $this->actingAs($admin)->put('/admin/system/texting', ['on' => true])->assertSessionHasErrors('on'); // no provider yet
    textingOn();
    expect(Texting::on())->toBeTrue();
    $this->actingAs($member)->get('/app')->assertRedirect('/verify-phone');

    // A super admin switches it off: nothing is texted, members aren't held at verification.
    $this->actingAs($admin)->put('/admin/system/texting', ['on' => false])->assertSessionHas('status', 'settings-saved');
    app(Settings::class)->flush();
    expect(Texting::on())->toBeFalse()->and(app(App\Support\Sms\SmsSender::class))->toBeInstanceOf(App\Domains\Platform\Deliveries\RecordingSmsSender::class);
    $this->actingAs($member)->get('/app')->assertOk();
    $this->actingAs($admin)->get('/admin/system/sms')->assertInertia(fn ($p) => $p->where('sms.texting', ['on' => false, 'configured' => true]));
    $this->actingAs($admin)->put('/admin/system/texting', ['on' => true])->assertSessionHas('status', 'settings-saved');
    app(Settings::class)->flush();
    expect(Texting::on())->toBeTrue();
});

it('invites teams from an uploaded list: checks each row first, then sends the good ones', function () {
    Illuminate\Support\Facades\Notification::fake();
    Illuminate\Support\Facades\Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Illuminate\Support\Facades\Artisan::call('t2t:catalogue:sync');
    $group = App\Domains\Network\Groups\Models\Group::query()->whereHas('industry', fn ($q) => $q->where('territory_unit', 'city'))->firstOrFail();
    $admin = kitAdmin();
    $csv = "Name,Email,City,State\n"
        ."Ava Agent,ava@example.com,Austin,TX\n"
        ."Ben Broker,ben@example.com,St. George,Utah\n" // abbreviation and a state's full name
        ."Dup,ava@example.com,Houston,TX\n"
        ."Bad,not-an-email,Austin,TX\n"
        ."Nowhere,cy@example.com,Atlantis,TX\n"
        ."Who,dee@example.com,Austin,ZZ\n"
        ."Island,eve@example.com,San Juan,PR\n"; // a place that doesn't take seats yet
    $file = Illuminate\Http\UploadedFile::fake()->createWithContent('list.csv', $csv);
    $res = $this->actingAs($admin)->post("/admin/groups/{$group->slug}/invites/preview", ['file' => $file], ['Accept' => 'application/json']);
    $res->assertOk();
    $rows = collect($res->json('rows'))->keyBy('line');
    expect($res->json('error'))->toBeNull()
        ->and($rows[2])->toMatchArray(['email' => 'ava@example.com', 'name' => 'Ava Agent', 'place' => 'Austin, TX', 'seat' => 'open', 'problem' => null])
        ->and($rows[3])->toMatchArray(['place' => 'Saint George, UT', 'problem' => null])
        ->and($rows[4]['problem'])->toBe('Listed twice (line 2)')
        ->and($rows[5]['problem'])->toBe('Not a valid email address')
        ->and($rows[6]['problem'])->toContain('No city "Atlantis"')
        ->and($rows[7]['problem'])->toContain('Unknown state')
        ->and($rows[8]['problem'])->toContain('not taking seats');

    // Sending the good rows: two invitations, then the same list shows them as already invited.
    $good = $rows->filter(fn ($r) => $r['problem'] === null)->map(fn ($r) => ['email' => $r['email'], 'city_id' => $r['city_id']])->values()->all();
    $this->actingAs($admin)->post("/admin/groups/{$group->slug}/invites", ['rows' => $good])->assertSessionHas('status', 'invites-sent');
    expect(Illuminate\Support\Facades\DB::table('seat_invites')->count())->toBe(2);
    $again = $this->actingAs($admin)->post("/admin/groups/{$group->slug}/invites/preview", ['file' => Illuminate\Http\UploadedFile::fake()->createWithContent('list.csv', $csv)], ['Accept' => 'application/json']);
    expect(collect($again->json('rows'))->firstWhere('line', 2))->toMatchArray(['seat' => 'invited', 'problem' => 'Already invited to this seat']);

    // A file without the right columns is explained, not guessed at.
    $bad = $this->actingAs($admin)->post("/admin/groups/{$group->slug}/invites/preview", ['file' => Illuminate\Http\UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2")], ['Accept' => 'application/json']);
    expect($bad->json('error'))->toContain('email, city and state');
    // Members who don't own the group can't use it.
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->post("/admin/groups/{$group->slug}/invites/preview", ['file' => $file], ['Accept' => 'application/json'])->assertForbidden();
});

it('lists every table as kept, kept for staff, or cleared at launch, so new tables need a decision', function () {
    $tables = collect(tableNames());
    $kept = App\Domains\Platform\Console\LaunchResetCommand::KEEP;
    $staff = array_keys(App\Domains\Platform\Console\LaunchResetCommand::STAFF_ROWS);
    $wipe = App\Domains\Platform\Console\LaunchResetCommand::WIPE;
    expect($tables->diff([...$kept, ...$staff, ...$wipe])->values()->all())->toBe([])
        ->and(array_intersect($kept, $wipe))->toBe([])->and(array_intersect($staff, $wipe))->toBe([])->and(array_intersect($kept, $staff))->toBe([]);
});

it('resets for launch: clears members, teams, referrals and the demo; keeps the catalogue, settings and staff; closes what the demo opened', function () {
    Illuminate\Support\Facades\Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Illuminate\Support\Facades\Artisan::call('t2t:catalogue:sync');
    config(['app.demo_install' => true]);
    App\Domains\Content\Demo\DemoWorkspace::create(true);
    $admin = kitAdmin();
    $industries = Illuminate\Support\Facades\DB::table('industries')->count();
    $groups = Illuminate\Support\Facades\DB::table('groups')->count();
    expect(Illuminate\Support\Facades\DB::table('referrals')->count())->toBeGreaterThan(0)
        ->and(Illuminate\Support\Facades\DB::table('groups')->where('status', 'live')->count())->toBeGreaterThan(0);
    // A real sign-off made by an admin stays.
    $real = App\Domains\Network\Industries\Models\Industry::query()->whereNull('compliance_approved_at')->firstOrFail();
    $real->forceFill(['compliance_approved_at' => now(), 'compliance_note' => 'Counsel memo 2026-11-01', 'status' => 'live'])->save();
    app(Settings::class)->set('general.company_name', 'Team to Team Referrals LLC');

    // Refused on a demo install, without the typed domain, and dry-run changes nothing.
    $this->artisan('t2t:launch:reset', ['--confirm' => 'localhost'])->assertFailed();
    config(['app.demo_install' => false, 'app.url' => 'https://teamtoteamreferrals.com']);
    $this->artisan('t2t:launch:reset', ['--dry-run' => true])->assertSuccessful();
    $this->artisan('t2t:launch:reset', ['--confirm' => 'wrong.com'])->assertFailed();
    expect(Illuminate\Support\Facades\DB::table('referrals')->count())->toBeGreaterThan(0);

    $this->artisan('t2t:launch:reset', ['--confirm' => 'teamtoteamreferrals.com'])->assertSuccessful();
    expect(Illuminate\Support\Facades\DB::table('referrals')->count())->toBe(0)
        ->and(Illuminate\Support\Facades\DB::table('teams')->count())->toBe(0)
        ->and(Illuminate\Support\Facades\DB::table('seats')->count())->toBe(0)
        ->and(User::query()->pluck('id')->all())->toBe([$admin->id])
        ->and($admin->fresh()->hasRole(Permissions::SUPER_ADMIN))->toBeTrue()
        ->and(Illuminate\Support\Facades\DB::table('industries')->count())->toBe($industries)
        ->and(Illuminate\Support\Facades\DB::table('groups')->count())->toBe($groups)
        ->and(Illuminate\Support\Facades\DB::table('cities')->count())->toBeGreaterThan(0)
        ->and(app(Settings::class)->get('general.company_name'))->toBe('Team to Team Referrals LLC')
        // The demo's open networks are closed again; the real sign-off is untouched.
        ->and(Illuminate\Support\Facades\DB::table('industries')->where('compliance_note', App\Domains\Platform\Console\LaunchResetCommand::DEMO_NOTE)->count())->toBe(0)
        ->and(Illuminate\Support\Facades\DB::table('groups')->whereIn('slug', array_keys(App\Domains\Content\Demo\DemoWorkspace::NETWORKS))->where('status', 'live')->count())->toBe(0)
        ->and($real->fresh()->status)->toBe('live');
    expect(Illuminate\Support\Facades\DB::table('activity_log')->where('event', 'launch-reset')->exists())->toBeTrue();
});

it('uses an email provider saved in Admin on the next request and the next queued job, without a restart', function () {
    // Long-running processes (Octane workers, queue workers) booted before Brevo was saved.
    expect(config('mail.default'))->not->toBe('brevo');
    $mailer = app('mail.manager')->mailer();
    $this->actingAs(kitAdmin())->put('/admin/system/email', ['driver' => 'brevo', 'from_address' => 'hello@example.com', 'from_name' => 'T2T', 'fields' => ['login' => '9a1b2c001@smtp-brevo.com', 'smtp_key' => 'xsmtpsib-secret']])
        ->assertSessionHasNoErrors();
    config(['mail.default' => 'array']); // as the worker's boot-time config would still say

    // Octane: before each request.
    (new App\Domains\Platform\Providers\ReapplyProviderSettings)->handle(new Laravel\Octane\Events\RequestReceived(app(), app(), Illuminate\Http\Request::create('/')));
    expect(config('mail.default'))->toBe('brevo')->and(config('mail.from.address'))->toBe('hello@example.com')
        ->and(app('mail.manager')->mailer())->not->toBe($mailer); // rebuilt with the new settings

    // Queue workers: before each job.
    config(['mail.default' => 'array']);
    event(new Illuminate\Queue\Events\JobProcessing('redis', Mockery::mock(Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing()));
    expect(config('mail.default'))->toBe('brevo');
});

it('sends email through Brevo’s API over HTTPS, for servers that block the email ports', function () {
    Illuminate\Support\Facades\Http::fake([App\Support\Mail\BrevoApiTransport::ENDPOINT => Illuminate\Support\Facades\Http::sequence()
        ->push(['messageId' => '<abc@smtp-relay.mailin.fr>'], 201)
        ->push(['code' => 'invalid_parameter', 'message' => 'Sender is not valid'], 400)]);
    $admin = kitAdmin();
    $this->actingAs($admin)->put('/admin/system/email', ['driver' => 'brevo_api', 'from_address' => 'no-reply@teamtoteamreferrals.com', 'from_name' => 'Team to Team Referrals', 'fields' => ['api_key' => 'xkeysib-secret']])
        ->assertSessionHasNoErrors();
    ApplyProviderSettings::refresh(app());
    expect(config('mail.default'))->toBe('brevo_api');

    Illuminate\Support\Facades\Mail::to('pat@example.com', 'Pat')->send((new Illuminate\Mail\Mailable)->subject('Hello')->html('<p>Hi Pat</p>')
        ->attachData('PDF', 'agreement.pdf', ['mime' => 'application/pdf']));
    Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->url() === App\Support\Mail\BrevoApiTransport::ENDPOINT && $r->hasHeader('api-key', 'xkeysib-secret')
        && $r['sender'] === ['email' => 'no-reply@teamtoteamreferrals.com', 'name' => 'Team to Team Referrals']
        && $r['to'] === [['email' => 'pat@example.com', 'name' => 'Pat']] && $r['subject'] === 'Hello' && str_contains($r['htmlContent'], 'Hi Pat')
        && $r['attachment'] === [['name' => 'agreement.pdf', 'content' => base64_encode('PDF')]]);
    expect(Illuminate\Support\Facades\DB::table('message_deliveries')->where('provider', 'brevo_api')->where('status', 'sent')->value('provider_message_id'))->toBe('<abc@smtp-relay.mailin.fr>');

    // Brevo's reason is shown when it refuses (e.g. a sender that isn't verified).
    $this->post('/admin/system/email/test', ['to' => 'pat@example.com'])->assertSessionHasErrors();
    expect(collect(session('errors')->all())->implode(' '))->toContain('Sender is not valid');
});
