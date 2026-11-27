<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\FieldRules;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Industries\Templates\Schemas;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Assist\Matches;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get(); // 0 Saint George, 1 Hurricane (~17 mi), 2 Austin, 3 Houston, 4 Fort Worth
    $this->seat = function (Group $g, string $name, int $city): Team {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $s = app(Seats::class);
        $s->approve($s->apply($g, $t, $this->cities[$city], 'L', null), null);

        return $t;
    };
    $this->answers = function (Group $g, array $over = []): array {
        $a = [];
        foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
            if (FieldRules::shows($f, $a)) {
                $a[$f['key']] = match ($f['type']) {
                    'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text',
                };
            }
        }

        return [...$a, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100', 'client_email' => 'tom@example.com', ...$over];
    };
    $this->member = function (Team $t, string $role = 'member'): User {
        $u = User::factory()->create();
        DB::table('team_members')->insert(['team_id' => $t->id, 'user_id' => $u->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    };
});

it('asks for the home’s address only when the client is selling, and keeps it hidden until a team accepts', function () {
    $g = Group::query()->whereHas('industry', fn ($q) => $q->where('slug', 'real-estate'))->where('is_launch', true)->firstOrFail();
    $a = ($this->seat)($g, 'Sender Realty', 2);
    $b = ($this->seat)($g, 'Houston Homes', 3);
    $form = app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'];
    expect(collect($form)->firstWhere('key', 'property_address'))->toMatchArray(['required' => true, 'sensitive' => true, 'when' => ['field' => 'side', 'in' => ['Selling', 'Both']]]);
    $send = fn (array $over) => $this->actingAs($a->leader)->post('/app/send', ['group' => $g->slug, 'city_id' => $this->cities[3]->id, 'answers' => ($this->answers)($g, $over), 'consent' => true, 'confirm_duplicate' => true]);

    // Buying: no address asked, and a stray one isn't kept.
    $send(['side' => 'Buying', 'property_address' => '1 Elm St'])->assertSessionHasNoErrors();
    expect(Referral::latest('id')->first()->answers)->not->toHaveKey('property_address');
    // Selling: required.
    $send(['side' => 'Selling', 'property_address' => ''])->assertSessionHasErrors('answers.property_address');
    $send(['side' => 'Selling', 'property_address' => '12 Oak Lane, Houston TX 77002', 'client_phone' => '+14355550101'])->assertSessionHasNoErrors();
    $r = Referral::latest('id')->first();
    $address = fn () => collect($this->actingAs($b->leader)->get("/app/referrals/{$r->ulid}")->viewData('page')['props']['referral']['answers'])->firstWhere('label', 'Address of the home they’re selling')['value'];
    expect($address())->toBeNull();
    app(Referrals::class)->accept($r->fresh(), $b->leader);
    expect($address())->toBe('12 Oak Lane, Houston TX 77002');
});

it('checks show-when rules in the form builder', function () {
    $base = [['key' => 'client_name', 'label' => 'Name', 'type' => 'text'], ['key' => 'client_phone', 'label' => 'Phone', 'type' => 'text'],
        ['key' => 'side', 'label' => 'Side', 'type' => 'choice', 'options' => ['Buying', 'Selling']]];
    expect(Schemas::checkForm([...$base, ['key' => 'addr', 'label' => 'Address', 'type' => 'text', 'sensitive' => true, 'when' => ['field' => 'side', 'in' => ['Selling']]]])['errors'])->toBe([])
        ->and(Schemas::checkForm([...$base, ['key' => 'addr', 'label' => 'Address', 'type' => 'text', 'when' => ['field' => 'side', 'in' => ['Renting']]]])['errors'])->not->toBe([])
        ->and(Schemas::checkForm([['key' => 'addr', 'label' => 'Address', 'type' => 'text', 'when' => ['field' => 'side', 'in' => ['Selling']]], ...$base])['errors'])->not->toBe([]);
    expect(FieldRules::shows(['when' => ['field' => 'side', 'in' => ['Selling', 'Both']]], ['side' => 'Both']))->toBeTrue()
        ->and(FieldRules::shows(['when' => ['field' => 'side', 'in' => ['Selling']]], []))->toBeFalse()
        ->and(FieldRules::shows(['key' => 'x'], []))->toBeTrue();
});

it('shows team members only their own referrals and open offers, first to accept wins, and only leaders decline', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Sender Solar', 2);
    $b = ($this->seat)($g, 'Holder Solar', 3);
    $priya = ($this->member)($b);
    $sam = ($this->member)($b);
    $assistant = ($this->member)($b, 'assistant');
    $send = fn (string $phone) => app(Referrals::class)->send($a->leader, $g, $this->cities[3], ($this->answers)($g, ['client_phone' => $phone]), true, null, true)['referral'];
    $one = $send('+14355550111');
    $two = $send('+14355550112');
    $inbox = fn (User $u) => collect($this->actingAs($u)->get('/app/inbox')->viewData('page')['props']['items'])->pluck('id')->sort()->values()->all();

    // Open offers: everyone in the team sees them, and can accept; only the leader or an assistant can decline.
    expect($inbox($priya))->toBe(collect([$one->ulid, $two->ulid])->sort()->values()->all());
    $this->actingAs($priya)->post("/app/referrals/{$one->ulid}/decline", ['reason' => 'Busy'])->assertForbidden();
    $this->actingAs($priya)->get("/app/referrals/{$one->ulid}")->assertInertia(fn ($p) => $p->where('canDecline', false));
    $this->actingAs($priya)->post("/app/referrals/{$one->ulid}/accept")->assertRedirect();
    $this->actingAs($sam)->post("/app/referrals/{$one->ulid}/accept")->assertStatus(422); // already taken
    expect($one->fresh()->receiver_user_id)->toBe($priya->id);

    // After that, Sam no longer sees Priya's client; Priya, the leader and the assistant do.
    expect($inbox($sam))->toBe([$two->ulid]);
    $this->actingAs($sam)->get("/app/referrals/{$one->ulid}")->assertNotFound();
    $this->actingAs($priya)->get("/app/referrals/{$one->ulid}")->assertOk();
    $this->actingAs($b->leader)->get("/app/referrals/{$one->ulid}")->assertInertia(fn ($p) => $p->where('acceptedBy', $priya->name)->where('canDecline', true));
    expect($inbox($assistant))->toHaveCount(2);
    expect(collect($this->actingAs($sam)->get('/app/pipeline')->viewData('page')['props'])->toJson())->not->toContain($one->ulid);
    expect(collect($this->actingAs($priya)->get('/app/pipeline')->viewData('page')['props'])->toJson())->toContain($one->ulid);
});

it('finds nearby teams nearest first, can search wider, and tells the receiver how far the client is', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Lone Star Solar', 2); // the sender, in Austin
    $b = ($this->seat)($g, 'Hurricane Sun', 1);
    $this->actingAs($a->leader);
    $near = $this->getJson('/app/send/preview?group=solar-installers-network&city_id='.$this->cities[0]->id)->assertOk()->json();
    expect($near['matches'][0])->toMatchArray(['team' => 'Hurricane Sun'])->and($near['radius'])->toBe(60)->and($near['radii'])->toBe([60, 100, 150]);
    $this->getJson('/app/send/preview?group=solar-installers-network&city_id='.$this->cities[4]->id.'&radius=150')->assertOk()->assertJson(['radius' => 150]);
    $this->getJson('/app/send/preview?group=solar-installers-network&city_id='.$this->cities[4]->id.'&radius=99')->assertStatus(422);
    expect(Matches::nearby($g, $this->cities[4]->id, null, 60))->toBe([])
        ->and(Matches::nearby($g, $this->cities[4]->id, null, 200)[0]['team'])->toBe('Lone Star Solar');

    // Sent to Hurricane for a client in Saint George: the receiver sees where the client is.
    $this->post('/app/send', ['group' => $g->slug, 'city_id' => $this->cities[1]->id, 'client_city_id' => $this->cities[0]->id, 'answers' => ($this->answers)($g), 'consent' => true])->assertSessionHasNoErrors();
    $r = Referral::latest('id')->first();
    expect($r->client_city_id)->toBe($this->cities[0]->id)->and($r->distance_miles)->toBeGreaterThan(10)->toBeLessThan(25);
    $this->actingAs($b->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('outOfArea.city', 'Saint George, UT')->where('outOfArea.miles', $r->distance_miles));

    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin)->get('/admin/groups/solar-installers-network')->assertInertia(fn ($p) => $p->where('emptyCityDemand.0', ['city' => 'Saint George, UT', 'referrals' => 1]));
});

it('tells everyone in the team about a new referral, each with their own one-tap link, and nudges the leader when members’ time is up', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Sender Solar', 2);
    $b = ($this->seat)($g, 'Holder Solar', 3);
    $priya = ($this->member)($b);
    $ref = app(Referrals::class)->send($a->leader, $g, $this->cities[3], ($this->answers)($g), true, null, true)['referral'];

    foreach ([$b->leader, $priya] as $person) {
        Notification::assertSentTo($person, App\Domains\Messaging\InAppNotification::class, fn ($n) => $n->type === 'referral.waiting');
    }
    // Priya's one-tap link gives the referral to her.
    $link = Referrals::quickLink($ref, $priya);
    expect($link)->toContain('u='.$priya->id);
    $this->post($link)->assertRedirect("/app/referrals/{$ref->ulid}");
    expect($ref->fresh()->receiver_user_id)->toBe($priya->id);

    // Another referral nobody takes: halfway through the window the leader hears, once.
    $two = app(Referrals::class)->send($a->leader, $g, $this->cities[3], ($this->answers)($g, ['client_phone' => '+14355550199']), true, null, true)['referral'];
    $this->travel((int) $g->rule('accept_hours') * 15 - 10)->minutes();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    expect(Notification::sent($b->leader, App\Domains\Messaging\InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.unclaimed')->count())->toBe(0);
    $this->travel(20)->minutes();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    expect(Notification::sent($b->leader, App\Domains\Messaging\InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.unclaimed')->count())->toBe(1);
    expect($two->fresh()->status)->toBe('sent');
});

it('lets the leader assign a waiting referral to a teammate, who is told and then works it', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Sender Solar', 2);
    $b = ($this->seat)($g, 'Holder Solar', 3);
    $priya = ($this->member)($b);
    $sam = ($this->member)($b);
    $ref = app(Referrals::class)->send($a->leader, $g, $this->cities[3], ($this->answers)($g), true, null, true)['referral'];

    $this->actingAs($b->leader)->get("/app/referrals/{$ref->ulid}")->assertInertia(fn ($p) => $p->has('assignTo', 3));
    $this->actingAs($sam)->post("/app/referrals/{$ref->ulid}/assign", ['member' => $priya->ulid])->assertForbidden();
    $this->actingAs($b->leader)->post("/app/referrals/{$ref->ulid}/assign", ['member' => $priya->ulid])->assertSessionHas('status', 'referral-assigned');
    expect($ref->fresh())->status->toBe('accepted')->receiver_user_id->toBe($priya->id);
    Notification::assertSentTo($priya, App\Domains\Messaging\InAppNotification::class, fn ($n) => $n->type === 'referral.assigned' && str_contains($n->title, 'assigned you'));
    // Sam can't work Priya's referral; Priya can, and the sender's messages reach her.
    $this->actingAs($sam)->post("/app/referrals/{$ref->ulid}/messages", ['body' => 'Hi'])->assertNotFound();
    app(Referrals::class)->message($ref->fresh(), $a->leader, 'Thanks for taking this');
    Notification::assertSentTo($priya, App\Domains\Messaging\InAppNotification::class, fn ($n) => $n->type === 'referral.message');
    expect($ref->fresh()->handler()?->id)->toBe($priya->id);
});

it('shows leaders how each member is doing, and keeps it from members', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Sender Solar', 2);
    $b = ($this->seat)($g, 'Holder Solar', 3);
    $priya = ($this->member)($b);
    $refs = app(Referrals::class);
    $one = $refs->send($a->leader, $g, $this->cities[3], ($this->answers)($g), true, null, true)['referral'];
    $refs->send($a->leader, $g, $this->cities[3], ($this->answers)($g, ['client_phone' => '+14355550188']), true, null, true);
    $this->travel(30)->minutes();
    $refs->accept($one->fresh(), $priya);

    $this->actingAs($priya)->get('/app/team/performance')->assertForbidden();
    $this->actingAs($b->leader)->get('/app/team/performance?days=30')->assertOk()->assertInertia(fn ($p) => $p->component('app/TeamPerformance')
        ->where('days', 30)->where('totals.offered', 2)->where('totals.accepted', 1)->where('totals.waiting', 1)
        ->where('rows.0.name', $priya->name)->where('rows.0.accepted', 1)->where('rows.0.medianMinutes', 30)->where('rows.0.open', 1));
});

it('lets only the leader choose how referrals are shared and how long members have', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $b = ($this->seat)($g, 'Holder Solar', 3);
    $priya = ($this->member)($b);
    $this->actingAs($b->leader)->get('/app/team')->assertInertia(fn ($p) => $p->where('sharing', ['mode' => 'everyone', 'minutes' => null]));
    $this->actingAs($priya)->post('/app/team/sharing', ['mode' => 'leader'])->assertForbidden();
    $this->actingAs($b->leader)->post('/app/team/sharing', ['mode' => 'turns', 'minutes' => 45])->assertSessionHasErrors('minutes');
    $this->post('/app/team/sharing', ['mode' => 'turns', 'minutes' => 60])->assertSessionHas('status', 'sharing-saved');
    expect(App\Domains\Referrals\LeadSharing::mode($b))->toBe('turns')->and(App\Domains\Referrals\LeadSharing::memberMinutes($b, $g))->toBe(60);
    $this->post('/app/team/sharing', ['mode' => 'everyone'])->assertSessionHasNoErrors();
    expect(App\Domains\Referrals\LeadSharing::memberMinutes($b, $g))->toBe((int) $g->rule('accept_hours') * 15); // a quarter
});

it('open to everyone: tells the leader once the members’ time (a quarter by default) is up', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Sender Solar', 2);
    $b = ($this->seat)($g, 'Holder Solar', 3);
    ($this->member)($b);
    app(Referrals::class)->send($a->leader, $g, $this->cities[3], ($this->answers)($g), true, null, true);
    $unclaimed = fn () => Notification::sent($b->leader, App\Domains\Messaging\InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.unclaimed')->count();
    $this->travel((int) $g->rule('accept_hours') * 15 - 5)->minutes();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    expect($unclaimed())->toBe(0);
    $this->travel(10)->minutes();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    expect($unclaimed())->toBe(1);
});

it('takes turns: one person at a time, in order, then open to all with the leader told', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Sender Solar', 2);
    $b = ($this->seat)($g, 'Holder Solar', 3);
    $priya = ($this->member)($b);
    $sam = ($this->member)($b);
    DB::table('teams')->where('id', $b->id)->update(['lead_sharing' => 'turns', 'member_accept_minutes' => 60]);
    $send = fn (string $phone) => app(Referrals::class)->send($a->leader, $g, $this->cities[3], ($this->answers)($g, ['client_phone' => $phone]), true, null, true)['referral'];
    $waiting = fn (User $u) => Notification::sent($u, App\Domains\Messaging\InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.waiting')->count();

    // Rotation: the leader (first member row), then Priya, then Sam.
    $one = $send('+14355550301');
    expect($one->fresh()->offered_user_id)->toBe($b->leader_id)->and($waiting($priya))->toBe(0);
    $this->actingAs($priya)->get("/app/referrals/{$one->ulid}")->assertNotFound();
    $this->actingAs($priya)->post("/app/referrals/{$one->ulid}/accept")->assertForbidden();

    $this->travel(61)->minutes();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    expect($one->fresh()->offered_user_id)->toBe($priya->id)->and($waiting($priya))->toBe(1);
    $this->actingAs($priya)->get("/app/referrals/{$one->ulid}")->assertInertia(fn ($p) => $p->where('acceptNote', fn ($n) => str_contains($n, 'your turn')));
    $this->actingAs($sam)->get("/app/referrals/{$one->ulid}")->assertNotFound();

    // The next new referral starts with the next person (Sam), so turns stay fair.
    $two = $send('+14355550302');
    expect($two->fresh()->offered_user_id)->toBe($sam->id);

    // Nobody takes the first: Sam, then everyone has had a turn: open to all, leader told once.
    $this->travel(61)->minutes();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    expect($one->fresh()->offered_user_id)->toBe($sam->id);
    $this->travel(61)->minutes();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    app(App\Domains\Referrals\Pipeline::class)->tick();
    expect($one->fresh()->offered_user_id)->toBeNull();
    expect(Notification::sent($b->leader, App\Domains\Messaging\InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.unclaimed')->count())->toBe(1);
    $this->actingAs($priya)->post("/app/referrals/{$one->ulid}/accept")->assertRedirect();
    expect($one->fresh()->receiver_user_id)->toBe($priya->id);
});

it('leader assigns: only the leader hears, and members see nothing until given it', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $a = ($this->seat)($g, 'Sender Solar', 2);
    $b = ($this->seat)($g, 'Holder Solar', 3);
    $priya = ($this->member)($b);
    DB::table('teams')->where('id', $b->id)->update(['lead_sharing' => 'leader']);
    $ref = app(Referrals::class)->send($a->leader, $g, $this->cities[3], ($this->answers)($g), true, null, true)['referral'];
    Notification::assertSentTo($b->leader, App\Domains\Messaging\InAppNotification::class, fn ($n) => $n->type === 'referral.waiting');
    Notification::assertNotSentTo($priya, App\Domains\Messaging\InAppNotification::class);
    $this->actingAs($priya)->get('/app/inbox')->assertInertia(fn ($p) => $p->has('items', 0));
    $this->actingAs($priya)->post("/app/referrals/{$ref->ulid}/accept")->assertForbidden();
    $this->actingAs($b->leader)->post("/app/referrals/{$ref->ulid}/assign", ['member' => $priya->ulid])->assertSessionHas('status', 'referral-assigned');
    $this->actingAs($priya)->get("/app/referrals/{$ref->ulid}")->assertOk();
});

it('opens every email with a plain, personal hello', function () {
    config(['mail.default' => 'array']);
    $u = User::factory()->create(['first_name' => 'Priya', 'last_name' => 'Shah']);
    app(Illuminate\Notifications\Channels\MailChannel::class)->send($u, new App\Domains\Messaging\Mail\PlatformMessage('referral.waiting', [['title' => 'New referral', 'body' => 'Tom in Austin', 'url' => null]]));
    $html = (string) collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->getHtmlBody();
    expect($html)->toContain('Hello Priya Shah,')->not->toContain('Hello!')->not->toMatch('/<h1[^>]*>\s*Hello/');
});

it('puts the website logo at the top of every email', function () {
    $u = User::factory()->create();
    app(Illuminate\Notifications\Channels\MailChannel::class)->send($u, new App\Domains\Messaging\Mail\PlatformMessage('referral.waiting', [['title' => 'New referral', 'body' => 'Tom in Austin', 'url' => null]]));
    $html = (string) collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->getHtmlBody();
    expect($html)->toContain('/brand/email-logo.png')->toContain('alt="'.config('app.name'));
    expect(file_exists(public_path('brand/email-logo.png')))->toBeTrue();
});
