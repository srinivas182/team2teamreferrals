<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Support\Usability\Usability;
use App\Domains\Support\Usability\UsabilityInvite;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    Artisan::call('t2t:catalogue:sync');
});

function usabilityStaff(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole(Permissions::STAFF);
    $u->givePermissionTo('support.manage');

    return $u;
}

it('writes each family’s script in its industry’s words, leaving out tasks its rules don’t allow', function () {
    $home = Usability::script('home');
    expect($home['tasks'])->not->toBeEmpty()->and($home['familyName'])->toBe('Home services')
        ->and(collect($home['tasks'])->pluck('task')->implode(' '))->not->toContain('{');
    // A restricted industry (no fees between members) gets no "check your fees" task.
    $restricted = Industry::query()->where('tier', 'B')->firstOrFail();
    $keys = collect(Usability::script($restricted->family, $restricted->slug)['tasks'])->pluck('key');
    expect($keys)->not->toContain('earnings')->and($keys)->toContain('send');
    $directory = Industry::query()->where('tier', 'C')->first();
    if ($directory !== null) {
        expect(collect(Usability::script($directory->family, $directory->slug)['tasks'])->pluck('key')->intersect(['send', 'receive', 'update', 'earnings'])->all())->toBe([]);
    }
    $hvac = Industry::query()->where('slug', 'hvac')->first();
    if ($hvac !== null) {
        expect(Usability::script($hvac->family, 'hvac')['tasks'][0]['task'])->toStartWith('You’re an HVAC');
    }
    expect(Usability::script('legal')['tasks'][0]['task'])->not->toContain('an l');
    expect(Usability::pattern('/app/referrals/01hzx3k9q8w7e6r5t4y3u2i1o0/notes?x=1'))->toBe('/app/referrals/{id}/notes')
        ->and(Usability::pattern('/admin/support/12'))->toBe('/admin/support/{id}');
});

it('invites testers, records where they get stuck and builds a fix list from it', function () {
    $staff = usabilityStaff();
    $this->actingAs(User::factory()->create())->get('/admin/usability')->assertForbidden();
    $this->actingAs($staff)->post('/admin/usability/invite', ['name' => 'Tess Tester', 'email' => 'tess@example.com', 'family' => 'property', 'journeys' => ['join', 'send', 'help']])
        ->assertSessionHas('status', 'usability-invited');
    $s = DB::table('usability_sessions')->where('email', 'tess@example.com')->first();
    expect($s->status)->toBe('invited')->and(json_decode((string) $s->journeys, true))->toBe(['join', 'send', 'help']);
    Notification::assertSentTo(new AnonymousNotifiable, UsabilityInvite::class, fn ($n, $c, $to) => $to->routes['mail'] === 'tess@example.com' && $n->tasks === 3);
    $this->post('/logout');

    // The emailed link is signed; the welcome page explains what's recorded.
    $this->get('/usability/s/'.$s->ulid)->assertForbidden();
    $this->get(Usability::startUrl($s))->assertOk()->assertInertia(fn ($p) => $p->component('usability/Welcome')->where('name', 'Tess')->has('tasks', 3)
        ->where('seo.robots', fn ($r) => str_contains((string) $r, 'noindex')));
    $begin = URL::temporarySignedRoute('usability.begin', now()->addHour(), ['session' => $s->ulid]);
    $this->post($begin)->assertRedirect('/')->assertCookie(Usability::COOKIE, Usability::token($s), false);
    $token = Usability::token($s);

    // The test bar's state, then what happens while testing.
    $this->withCredentials()->withUnencryptedCookie(Usability::COOKIE, $token)->getJson('/usability/state')->assertOk()->assertJson(['active' => true, 'current' => 0])->assertJsonCount(3, 'tasks');
    $this->withCredentials()->withUnencryptedCookie(Usability::COOKIE, $token)->postJson('/usability/events', ['events' => [
        ['kind' => 'page', 'path' => '/register?ref=x', 'seconds' => 40],
        ['kind' => 'stuck', 'path' => '/register', 'seconds' => 90, 'detail' => 'No progress for 90 seconds'],
        ['kind' => 'error', 'path' => '/register', 'detail' => 'Errors in: phone, license'],
        ['kind' => 'nonsense', 'path' => '/'],
        ['kind' => 'done', 'task' => 'join', 'seconds' => 300],
        ['kind' => 'rage', 'path' => '/app/send', 'detail' => 'Clicked button “Next” again and again'],
    ]])->assertOk()->assertJson(['current' => 1, 'finished' => false]);
    expect(DB::table('usability_events')->where('session_id', $s->id)->count())->toBe(5)
        ->and(DB::table('usability_events')->where(['session_id' => $s->id, 'kind' => 'page'])->value('path'))->toBe('/register') // no query string
        ->and(DB::table('usability_events')->where(['session_id' => $s->id, 'kind' => 'rage'])->value('task'))->toBe('send') // the task they were on
        ->and(DB::table('usability_issues')->count())->toBe(3)
        ->and(DB::table('usability_issues')->where('kind', 'rage')->value('severity'))->toBe('low');

    // A second and third tester stuck in the same place: one line on the fix list, now high severity.
    foreach (['two@example.com', 'three@example.com'] as $email) {
        $other = Usability::start(Usability::invite('Other', $email, 'home', null, ['join'], $staff));
        Usability::record($other, [['kind' => 'stuck', 'path' => '/register', 'task' => 'join']]);
    }
    $stuck = DB::table('usability_issues')->where('kind', 'stuck')->first();
    expect(DB::table('usability_issues')->where('kind', 'stuck')->count())->toBe(1)->and($stuck->hits)->toBe(3)->and($stuck->severity)->toBe('high')
        ->and(json_decode((string) $stuck->families, true))->toBe(['property', 'home']);

    // Giving up the rest finishes the test; the bar then goes away.
    $this->withCredentials()->withUnencryptedCookie(Usability::COOKIE, $token)->postJson('/usability/events', ['events' => [
        ['kind' => 'gave_up', 'task' => 'send', 'detail' => 'Couldn’t find the city'], ['kind' => 'done', 'task' => 'help'],
    ]])->assertJson(['finished' => true, 'active' => false]);
    expect(DB::table('usability_issues')->where('kind', 'gave_up')->value('severity'))->toBe('high');
    $this->withCredentials()->withUnencryptedCookie(Usability::COOKIE, $token)->getJson('/usability/state')->assertJson(['active' => false]);
    $this->withCredentials()->withUnencryptedCookie(Usability::COOKIE, $token)->postJson('/usability/events', ['events' => [['kind' => 'page']]])->assertNotFound();
    $this->withCredentials()->withUnencryptedCookie(Usability::COOKIE, $s->ulid.'.0000000000000000')->getJson('/usability/state')->assertJson(['active' => false]); // forged

    // Staff see coverage, the fix list and each tester's timeline, and work the list.
    $this->actingAs($staff)->get('/admin/usability?family=money')->assertOk()->assertInertia(fn ($p) => $p->component('admin/usability/Index')
        ->where('coverage.0.family', 'property')->where('coverage.0.finished', 1)->where('coverage.0.target', 3)
        ->where('issues.0.severity', 'high')->where('family', 'money')->where('script.familyName', 'Insurance and financial')->has('sessions', 3));
    $this->get('/admin/usability/'.$s->ulid)->assertOk()->assertInertia(fn ($p) => $p->component('admin/usability/Session')->where('tasks.1.outcome', 'gave_up')
        ->where('tasks.1.note', 'Couldn’t find the city')->has('events', 7));
    $this->put('/admin/usability/issues/'.$stuck->id, ['status' => 'fixed', 'severity' => 'medium', 'notes' => 'Clearer licence step'])->assertSessionHas('status', 'usability-issue-saved');
    // A fixed problem that happens again is open again.
    Usability::record(Usability::start(Usability::invite('Four', 'four@example.com', 'home', null, ['join'], $staff)), [['kind' => 'stuck', 'path' => '/register']]);
    expect(DB::table('usability_issues')->where('id', $stuck->id)->value('status'))->toBe('open');
});

it('stops a test from the bar and refuses invitations with no tasks that apply', function () {
    $staff = usabilityStaff();
    $s = Usability::start(Usability::invite('Sam', 'sam@example.com', 'legal', null, ['join', 'help'], $staff));
    $this->withCredentials()->withUnencryptedCookie(Usability::COOKIE, Usability::token($s))->post('/usability/stop')->assertRedirect('/usability/thanks');
    expect(DB::table('usability_sessions')->where('id', $s->id)->value('status'))->toBe('finished');
    $this->get('/usability/thanks')->assertOk();
    $restricted = Industry::query()->where('tier', 'C')->first() ?? Industry::query()->where('tier', 'B')->firstOrFail();
    $this->actingAs($staff)->post('/admin/usability/invite', ['name' => 'X', 'email' => 'x@example.com', 'family' => $restricted->family, 'industry' => $restricted->slug, 'journeys' => ['earnings']])
        ->assertSessionHasErrors('journeys');
    expect(DB::table('usability_sessions')->where('email', 'x@example.com')->exists())->toBeFalse();
});

it('publishes a press kit with live figures, the press contact, the tour and logo downloads', function () {
    app(Settings::class)->set('general.press_email', 'press@example.com');
    $p = $this->get('/press')->assertOk()->viewData('page')['props'];
    expect($p['figures']['industries'])->toBe(DB::table('industries')->count())
        ->and($p['contact']['email'])->toBe('press@example.com')
        ->and($p['logos'])->not->toBeEmpty()
        ->and(collect($p['seo']['schema'])->pluck('@type')->all())->toContain('Organization', 'FAQPage', 'BreadcrumbList')
        ->and($p['faq'][0]['q'])->toContain('press enquiry');
    if (is_file(public_path('press-kit/tour.mp4'))) {
        expect($p['video']['transcript'])->toHaveCount(9)->and(collect($p['seo']['schema'])->firstWhere('@type', 'VideoObject')['duration'])->toBe('PT58S')
            ->and(file_get_contents(public_path('press-kit/tour.vtt')))->toContain('WEBVTT')->toContain('Claim your city');
    }
    $this->get('/press/logos.zip')->assertOk()->assertDownload('team-to-team-referrals-logos.zip');
    $this->get('/sitemaps/press.xml')->assertOk()->assertSee('/press');
});

it('adds the founding-member launch emails as a draft campaign, sent to no one until reviewed', function () {
    $c = DB::table('campaigns')->where('name', 'Launch: founding members')->first();
    expect($c->status)->toBe('draft')->and(json_decode((string) $c->audience, true))->toBe(['source' => 'reservation']);
    $steps = DB::table('campaign_steps')->where('campaign_id', $c->id)->orderBy('position')->get();
    expect($steps)->toHaveCount(3)->and($steps[0]->wait_hours)->toBe(0)->and($steps[0]->subject_b)->not->toBeNull()
        ->and($steps->pluck('cta_url')->unique()->all())->toBe(['/register']);
});
