<?php

use App\Domains\Growth\GrowthMessage;
use App\Domains\Growth\Prospect;
use App\Domains\Growth\Prospects;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->other = Group::where('slug', '!=', 'solar-installers-network')->where('industry_id', '!=', $this->g->industry_id)->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderByDesc('population')->get();
    $this->staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now(), 'first_name' => 'Sam', 'last_name' => 'Staff']);
    $this->staff->assignRole(Permissions::SUPER_ADMIN);
    $this->owner = User::factory()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    DB::table('group_owners')->insert(['group_id' => $this->g->id, 'user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->state = fn (City $c) => DB::table('states')->where('id', $c->state_id)->value('code');
});

it('adds prospects by themselves and moves them along as they sign up, apply and join', function () {
    $user = User::factory()->create(['email' => 'pat@example.com', 'first_name' => 'Pat', 'last_name' => 'Lee']);
    event(new Registered($user));
    $p = Prospect::where('email', 'pat@example.com')->firstOrFail();
    expect($p)->source->toBe('signup')->consent_basis->toBe('signed_up')->stage->toBe('signed_up')->user_id->toBe($user->id)->score->toBe(12);

    $team = Team::forLeader($user);
    $seats = app(Seats::class);
    $app = $seats->apply($this->g, $team, $this->cities[0], 'L', null);
    expect($p->fresh()->stage)->toBe('applied')->and($p->fresh()->group_id)->toBe($this->g->id);
    $seats->approve($app, null);
    expect($p->fresh())->stage->toBe('joined')->joined_at->not->toBeNull()->score->toBe(40);
    expect(DB::table('prospect_activities')->where('prospect_id', $p->id)->pluck('type')->all())->toContain('created', 'signup', 'applied', 'joined');

    // A pre-launch reservation and a "Joining a network" message become prospects; other messages don't.
    $token = Illuminate\Support\Str::random(40);
    DB::table('reservations')->insert(['group_id' => $this->g->id, 'city_id' => $this->cities[1]->id, 'email' => 'res@example.com', 'name' => 'Rae Smith', 'business' => 'Smith Solar',
        'token_hash' => hash('sha256', $token), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    app(App\Domains\Network\Prelaunch\Reservations::class)->confirm($token);
    expect(Prospect::where('email', 'res@example.com')->firstOrFail())->source->toBe('reservation')->stage->toBe('interested')->city_id->toBe($this->cities[1]->id)
        ->first_name->toBe('Rae')->business->toBe('Smith Solar')->group_id->toBe($this->g->id);
    foreach (['joining' => 'join@example.com', 'billing' => 'bill@example.com'] as $topic => $email) {
        $id = DB::table('contact_messages')->insertGetId(['name' => 'Jo Diaz', 'email' => $email, 'topic' => $topic, 'message' => 'I would like to join the network in Austin please.', 'ip_hash' => 'x', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        event(new App\Domains\Content\Events\ContactMessageReceived($id));
    }
    expect(Prospect::where('email', 'join@example.com')->value('source'))->toBe('contact_form')->and(Prospect::where('email', 'bill@example.com')->exists())->toBeFalse();
});

it('lets staff work prospects: add, stage, owner, notes, tasks with reminders, views and bulk changes', function () {
    $as = fn () => $this->actingAs($this->staff);
    $as()->post('/admin/growth/prospects', ['email' => 'Ana@Example.com', 'first_name' => 'Ana', 'business' => 'Ana Homes', 'industry_id' => $this->g->industry_id])->assertSessionHas('status', 'prospect-added');
    $p = Prospect::where('email', 'ana@example.com')->firstOrFail();
    expect($p)->source->toBe('manual')->owner_id->toBe($this->staff->id)->consent_basis->toBe('added_by_staff');
    $as()->post('/admin/growth/prospects', ['email' => 'ana@example.com'])->assertSessionHasErrors('email');
    $as()->post("/admin/growth/prospects/{$p->ulid}/stage", ['stage' => 'lost'])->assertSessionHasErrors('lost_reason');
    $as()->post("/admin/growth/prospects/{$p->ulid}/stage", ['stage' => 'contacted'])->assertSessionHas('status', 'prospect-saved');
    $as()->post("/admin/growth/prospects/{$p->ulid}/owner", ['owner_id' => $this->owner->id])->assertSessionHasErrors('owner_id'); // not staff, and no network here
    $other = User::factory()->create(['first_name' => 'Gia']);
    $other->givePermissionTo('growth.manage');
    $as()->post("/admin/growth/prospects/{$p->ulid}/owner", ['owner_id' => $other->id])->assertSessionHas('status', 'prospect-saved');
    Notification::assertSentTo($other, InAppNotification::class, fn ($n) => $n->type === 'growth.tasks' && str_contains($n->title, 'Ana'));
    $as()->post("/admin/growth/prospects/{$p->ulid}/notes", ['note' => 'Met at the Austin expo'])->assertSessionHas('status', 'prospect-noted');
    $as()->post("/admin/growth/prospects/{$p->ulid}/tasks", ['title' => 'Call Ana', 'due_at' => now()->toDateString(), 'assignee_id' => $other->id])->assertSessionHas('status', 'prospect-task-added');
    expect(Prospects::remindTasks())->toBe(1)->and(Prospects::remindTasks())->toBe(0); // once a day
    Notification::assertSentTo($other, InAppNotification::class, fn ($n) => $n->type === 'growth.tasks' && $n->title === 'A prospect task is due' && str_contains($n->body, 'Call Ana'));
    $task = DB::table('prospect_tasks')->value('id');
    $as()->post("/admin/growth/prospects/tasks/{$task}");
    expect(DB::table('prospect_tasks')->value('done_at'))->not->toBeNull();
    $as()->get("/admin/growth/prospects/{$p->ulid}")->assertOk()->assertInertia(fn ($pg) => $pg->component('growth/Prospect')->where('prospect.stage', 'contacted')->where('layout', 'admin')
        ->where('activities.0.type', 'task')->has('tasks', 1)->where('tasks.0.done', true));

    Prospects::upsert(['email' => 'zed@example.com', 'state_code' => 'TX'], 'manual', 'added_by_staff', $this->staff);
    $as()->get('/admin/growth/prospects?q=ana')->assertInertia(fn ($pg) => $pg->component('growth/Prospects')->where('prospects.total', 1)->where('prospects.data.0.name', 'Ana'));
    $as()->get('/admin/growth/prospects?state=TX')->assertInertia(fn ($pg) => $pg->where('prospects.total', 1));
    $as()->get('/admin/growth/prospects?view=board')->assertInertia(fn ($pg) => $pg->where('prospects', null)->where('board.0.stage', 'new')->where('board.0.count', 1)->where('board.1.count', 1));
    $as()->post('/admin/growth/prospects/views', ['name' => 'Texas', 'filters' => ['state' => 'TX', 'evil' => 'x']]);
    expect(json_decode((string) DB::table('prospect_views')->value('filters'), true))->toBe(['state' => 'TX']);
    $as()->post('/admin/growth/prospects/bulk', ['ids' => Prospect::pluck('ulid')->all(), 'action' => 'stage', 'stage' => 'interested'])->assertSessionHas('status', 'prospects-updated');
    expect(Prospect::where('stage', 'interested')->count())->toBe(2);
    // Members can't reach any of it.
    $this->actingAs(User::factory()->create())->get('/admin/growth/prospects')->assertForbidden();
});

it('shows network owners only their networks’ prospects', function () {
    $mine = Prospects::upsert(['email' => 'mine@example.com', 'group_id' => $this->g->id], 'manual', 'added_by_staff');
    $industry = Prospects::upsert(['email' => 'industry@example.com', 'industry_id' => $this->g->industry_id], 'manual', 'added_by_staff');
    $theirs = Prospects::upsert(['email' => 'theirs@example.com', 'group_id' => $this->other->id], 'manual', 'added_by_staff');
    $base = "/app/group/{$this->g->slug}/prospects";
    $this->actingAs($this->owner)->get($base)->assertOk()->assertInertia(fn ($pg) => $pg->where('layout', 'portal')->where('prospects.total', 2)->where('industries', []));
    $this->actingAs($this->owner)->get("{$base}/{$theirs->ulid}")->assertNotFound();
    $this->actingAs($this->owner)->get("{$base}/{$mine->ulid}")->assertOk();
    $this->actingAs($this->owner)->post("{$base}/{$industry->ulid}/notes", ['note' => 'Called'])->assertSessionHas('status', 'prospect-noted');
    $this->actingAs($this->owner)->post($base, ['email' => 'new@example.com'])->assertRedirect();
    expect(Prospect::where('email', 'new@example.com')->value('group_id'))->toBe($this->g->id);
    $this->actingAs($this->owner)->get("/app/group/{$this->other->slug}/prospects")->assertForbidden();
    $this->actingAs($this->owner)->get('/admin/growth/prospects')->assertForbidden();
    // Links in notifications land in the right place.
    $this->actingAs($this->owner)->get("/app/prospects/{$mine->ulid}")->assertRedirect("{$base}/{$mine->ulid}");
    $this->actingAs($this->staff)->get("/app/prospects/{$mine->ulid}")->assertRedirect("/admin/growth/prospects/{$mine->ulid}");
    $this->actingAs($this->owner)->get('/app/owner/prospects')->assertRedirect($base);
});

it('imports a CSV after the columns are matched and the list is confirmed not bought', function () {
    $c = $this->cities[0];
    $csv = "\xEF\xBB\xBFEmail Address,First Name,Surname,Company,City,State\nann@example.com,Ann,Ray,Ray Realty,{$c->name},".($this->state)($c)."\nnot-an-email,Bob,,,,\nANN@example.com,,,,,\nforgot@example.com,Fay,,,,\n";
    $forgotten = Prospects::upsert(['email' => 'forgot@example.com'], 'manual', 'added_by_staff');
    Prospects::forget($forgotten, $this->staff);
    $this->actingAs($this->staff)->post('/admin/growth/prospects/import', ['file' => UploadedFile::fake()->createWithContent('expo.csv', $csv)])
        ->assertSessionHas('import_preview', fn ($p) => $p['headers'] === ['Email Address', 'First Name', 'Surname', 'Company', 'City', 'State'] && $p['guess'] === ['email', 'first_name', 'last_name', 'business', 'city', 'state'] && count($p['sample']) === 4);
    $preview = session('import_preview');
    $this->actingAs($this->staff)->post('/admin/growth/prospects/import/confirm', ['token' => $preview['token'], 'name' => 'expo.csv', 'mapping' => $preview['guess']])->assertSessionHasErrors('not_purchased');
    $this->actingAs($this->staff)->post('/admin/growth/prospects/import/confirm', ['token' => $preview['token'], 'name' => 'expo.csv', 'mapping' => $preview['guess'], 'not_purchased' => true])
        ->assertSessionHas('import_result', fn ($r) => $r['added'] === 1 && $r['updated'] === 1 && $r['skipped'] === 2 && $r['problems'] === ['Row 3: no valid email.']);
    expect(Prospect::where('email', 'ann@example.com')->firstOrFail())->first_name->toBe('Ann')->last_name->toBe('Ray')->city_id->toBe($c->id)->consent_basis->toBe('imported_not_purchased')->source_detail->toBe('expo.csv');
    expect(Prospect::where('email', 'forgot@example.com')->exists())->toBeFalse();
    expect(DB::table('activity_log')->where('event', 'prospects-imported')->exists())->toBeTrue();
});

it('forgets a person completely and never adds them again', function () {
    $p = Prospects::upsert(['email' => 'gone@example.com'], 'manual', 'added_by_staff');
    Prospects::note($p, $this->staff, 'Some history');
    $this->actingAs($this->staff)->delete("/admin/growth/prospects/{$p->ulid}")->assertRedirect('/admin/growth/prospects');
    expect(Prospect::count())->toBe(0)->and(DB::table('prospect_activities')->count())->toBe(0)->and(DB::table('email_suppressions')->value('email_hash'))->toBe(hash('sha256', 'gone@example.com'));
    expect(DB::table('email_suppressions')->value('email_hash'))->not->toContain('gone');
    event(new Registered(User::factory()->create(['email' => 'gone@example.com'])));
    expect(Prospect::count())->toBe(0);
});

it('tells prospects in the city when a seat opens with nobody waiting, following the bulk-sender rules', function () {
    $s = app(Seats::class);
    $holder = Team::forLeader(User::factory()->create());
    $s->approve($s->apply($this->g, $holder, $this->cities[1], 'L', null), null);
    $seat = Seat::where('primary_team_id', $holder->id)->firstOrFail();
    $near = Prospects::upsert(['email' => 'near@example.com', 'first_name' => 'Nia', 'group_id' => $this->g->id, 'city_id' => $this->cities[1]->id], 'manual', 'added_by_staff');
    $far = Prospects::upsert(['email' => 'far@example.com', 'group_id' => $this->g->id, 'city_id' => $this->cities[0]->id], 'manual', 'added_by_staff');
    $otherNetwork = Prospects::upsert(['email' => 'other@example.com', 'group_id' => $this->other->id, 'city_id' => $this->cities[1]->id], 'manual', 'added_by_staff');

    // Sending is off until an admin switches it on: nothing goes out, and the timeline says so.
    $s->release($seat, 'test');
    Notification::assertNothingSentTo(new AnonymousNotifiable);
    expect(DB::table('prospect_activities')->where(['prospect_id' => $near->id, 'type' => 'email'])->value('body'))->toContain('switched off');

    $this->actingAs($this->staff)->post('/admin/growth/settings', ['sending' => true, 'from_address' => 'hello@news.t2t.example', 'from_name' => 'T2T'])->assertSessionHasErrors('sending'); // no postal address yet
    app(Settings::class)->set('general.postal_address', '1 Main St, Austin, TX 78701');
    $this->actingAs($this->staff)->post('/admin/growth/settings', ['sending' => true, 'from_address' => 'hello@news.t2t.example', 'from_name' => 'T2T'])->assertSessionHas('status', 'growth-settings-saved');
    $near->forceFill(['alerted_at' => null])->save();
    $s->approve($s->apply($this->g, $holder, $this->cities[1], 'L', null), null);
    $s->release(Seat::where('primary_team_id', $holder->id)->firstOrFail(), 'test');

    $sent = [];
    Notification::assertSentOnDemand(GrowthMessage::class, function ($n, $channels, $notifiable) use (&$sent) {
        $sent[] = $notifiable->routes['mail'];
        $mail = $n->toMail($notifiable);
        expect($mail->subject)->toContain('seat just opened')->and($mail->from)->toBe(['hello@news.t2t.example', 'T2T'])
            ->and(implode(' ', [...$mail->introLines, ...$mail->outroLines]))->toContain('Hi Nia')->toContain('1 Main St, Austin')->toContain('Unsubscribe')
            ->and($mail->actionUrl)->toContain('utm_campaign=seat-open');
        $email = new Email;
        foreach ($mail->callbacks as $cb) {
            $cb($email);
        }
        expect($email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
            ->and($email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toContain('/growth/unsubscribe/');

        return true;
    });
    expect($sent)->toBe(['near@example.com']);

    // At most once a week.
    $s->approve($s->apply($this->g, $holder, $this->cities[1], 'L', null), null);
    $s->release(Seat::where('primary_team_id', $holder->id)->firstOrFail(), 'test');
    expect(DB::table('prospect_activities')->where(['prospect_id' => $near->id, 'type' => 'email'])->count())->toBe(2);
    expect(DB::table('prospect_activities')->whereIn('prospect_id', [$far->id, $otherNetwork->id])->where('type', 'email')->count())->toBe(0);

    // One-click unsubscribe from the mail app: no sign-in, no form token; the link can't be forged.
    $url = URL::signedRoute('growth.unsubscribe', ['prospect' => $near->ulid]);
    $this->get($url)->assertOk()->assertInertia(fn ($pg) => $pg->component('GrowthUnsubscribe')->where('done', false));
    $this->post(str_replace($near->ulid, $far->ulid, $url))->assertForbidden();
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    expect($near->fresh()->unsubscribed_at)->not->toBeNull()->and(Prospects::suppressed('near@example.com'))->toBeTrue();
    $this->travel(8)->days();
    $s->approve($s->apply($this->g, $holder, $this->cities[1], 'L', null), null);
    $s->release(Seat::where('primary_team_id', $holder->id)->firstOrFail(), 'test');
    expect(DB::table('prospect_activities')->where(['prospect_id' => $near->id, 'type' => 'email'])->where('body', 'like', '%seat just opened%')->count())->toBe(2);
});

it('scores leads from how far they got and how engaged they are', function () {
    $p = Prospects::upsert(['email' => 'score@example.com'], 'manual', 'added_by_staff');
    expect(Prospects::rescore($p))->toBe(0)->and($p->temperature())->toBe('cold');
    Prospects::setStage($p, 'interested', $this->staff);
    foreach (['email_click', 'email_click', 'visit'] as $t) {
        Prospects::log($p, null, $t, null);
    }
    expect(Prospects::rescore($p))->toBe(16)->and($p->fresh()->temperature())->toBe('warm');
    Prospects::log($p, null, 'reply', null);
    Prospects::log($p, null, 'reply', null);
    expect(Prospects::rescore($p))->toBe(32)->and($p->fresh()->temperature())->toBe('hot');
    $this->travel(91)->days();
    expect(Prospects::rescore($p->fresh()))->toBe(8); // engagement fades after 90 days
});
