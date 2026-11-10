<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Conversations;
use App\Domains\Referrals\Events\ReferralChanged;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create(['first_name' => explode(' ', $n)[0]])))->update(['name' => $n]);
    [$this->a, $this->b] = [$mk('Red Rock Solar'), $mk('Hurricane Sun')];
    $s->approve($s->apply($g, $this->a, $cities[0], 'L', null), null);
    $s->approve($s->apply($g, $this->b, $cities[1], 'L', null), null);
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->r = app(Referrals::class)->send($this->a->leader, $g, $cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null)['referral'];
    $this->url = "/app/referrals/{$this->r->ulid}";
});

it('pushes "referral changed" to both teams and the referral, with no client details', function () {
    Event::fake([ReferralChanged::class]);
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['body' => 'Tom prefers texts: (435) 555-0100']);
    Event::assertDispatched(ReferralChanged::class, function (ReferralChanged $e) {
        $names = array_map(fn ($c) => $c->name, $e->broadcastOn());
        $payload = json_encode($e->broadcastWith());

        return $e->what === 'message' && $names === ["private-referrals.{$this->r->ulid}", "private-teams.{$this->a->ulid}", "private-teams.{$this->b->ulid}"]
            && ! str_contains($payload, 'Tom') && ! str_contains($payload, '555');
    });
});

it('lets only the two teams listen to a referral, and each team only to itself', function () {
    expect(Conversations::canListenToReferral($this->a->leader, $this->r->ulid))->toBeTrue()
        ->and(Conversations::canListenToReferral($this->b->leader, $this->r->ulid))->toBeTrue()
        ->and(Conversations::canListenToReferral(User::factory()->create(), $this->r->ulid))->toBeFalse()
        ->and(Conversations::canListenToTeam($this->a->leader, $this->a->ulid))->toBeTrue()
        ->and(Conversations::canListenToTeam($this->a->leader, $this->b->ulid))->toBeFalse();
});

it('counts unread messages and shows "Seen" once the other team reads them', function () {
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['body' => 'Hi']);
    $this->post("{$this->url}/messages", ['body' => 'Any questions?']);
    $this->actingAs($this->b->leader)->get('/app/inbox')->assertInertia(fn ($p) => $p->where('referralUnread.inbox', 2));
    $this->get($this->url);
    $this->get('/app/inbox')->assertInertia(fn ($p) => $p->where('referralUnread.inbox', 0));
    $this->actingAs($this->a->leader)->get($this->url)->assertInertia(fn ($p) => $p->where('messages.1.seen', true));
});

it('mutes a thread’s messages but never its deadline reminders', function () {
    app(Referrals::class)->accept($this->r->fresh(), $this->b->leader);
    $this->actingAs($this->b->leader)->post("{$this->url}/mute")->assertSessionHas('status', 'thread-muted');
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['body' => 'Hello?']);
    Notification::assertNotSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.message');
    $this->travel(22)->hours();
    $this->travel(30)->minutes();
    Artisan::call('t2t:referrals:expire');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.deadline');
});

it('offers quick replies, editable by leaders and assistants', function () {
    $this->actingAs($this->b->leader)->get($this->url)->assertInertia(fn ($p) => $p->where('quickReplies', Conversations::DEFAULT_QUICK_REPLIES));
    $this->post('/app/team/quick-replies', ['replies' => ['On it!', '', 'Booked for Friday.']])->assertSessionHas('status', 'quick-replies-saved');
    $this->get($this->url)->assertInertia(fn ($p) => $p->where('quickReplies', ['On it!', 'Booked for Friday.']));
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->b->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->post('/app/team/quick-replies', ['replies' => ['x']])->assertForbidden();
});

it('searches the team’s conversations with the same privacy rules', function () {
    $this->actingAs($this->a->leader)->post("{$this->url}/messages", ['body' => 'HOA meeting Tuesday, call (435) 555-0100']);
    $this->actingAs($this->b->leader)->get('/app/messages/search?q=hoa')->assertInertia(fn ($p) => $p->has('results', 1)
        ->where('results.0.client', 'Tom B.')->where('results.0.excerpt', fn ($e) => str_contains($e, '[phone shown after accepting]')));
    $this->actingAs(Team::forLeader(User::factory()->create())->leader)->get('/app/messages/search?q=hoa')->assertInertia(fn ($p) => $p->has('results', 0));
});

it('tells the browser which team channel to use only when real-time is on', function () {
    $this->actingAs($this->a->leader)->get('/app')->assertInertia(fn ($p) => $p->where('realtime', null));
    config(['broadcasting.default' => 'mercure']);
    $this->get('/app')->assertInertia(fn ($p) => $p->where('realtime.team', $this->a->ulid));
});

it('grants the referral channel through the real Mercure driver only to the two teams', function () {
    config(['broadcasting.default' => 'mercure', 'broadcasting.connections.mercure.secret' => str_repeat('k', 40), 'broadcasting.connections.mercure.cookie_name' => 'mercure_access_token']);
    app()->forgetInstance(Illuminate\Contracts\Broadcasting\Factory::class);
    app(Illuminate\Broadcasting\BroadcastManager::class)->purge('mercure');
    require base_path('routes/channels.php');
    $ask = fn (User $u) => $this->actingAs($u)->postJson('/broadcasting/auth', ['channel_names' => ["private-referrals.{$this->r->ulid}"]])->assertOk();

    expect($ask($this->b->leader)->json('channel_names.0'))->toBe(['name' => "private-referrals.{$this->r->ulid}"]);
    $outsider = Team::forLeader(User::factory()->create())->leader;
    expect($ask($outsider)->json('channel_names.0.denied'))->toBeTrue();
});
