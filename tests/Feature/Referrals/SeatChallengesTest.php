<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\SeatWaitlist;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->holder, $this->backup, $this->challenger] = [$mk('Sender Solar'), $mk('Holder Solar'), $mk('Backup Solar'), $mk('Challenger Solar')];
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    foreach ([$this->holder, $this->backup, $this->challenger] as $t) {
        $s->approve($s->apply($this->g, $t, $this->cities[1], 'L', null), null);
    }
    $this->seat = Seat::where('territory_id', $this->cities[1]->id)->firstOrFail();
    // Everyone has passed the network training (the gate would otherwise block them after the grace period).
    $t = app(App\Domains\Network\Training\Training::class);
    foreach ([$this->a, $this->holder, $this->backup, $this->challenger] as $team) {
        $t->submit($team->leader, $t->module($this->g->industry), array_column(json_decode((string) $t->module($this->g->industry)->questions, true), 'answer'));
    }
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $this->ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
        $this->ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->n = 0;
    // Five referrals the holder never answers: a score of 0 (they move to the backup when they expire).
    $this->ignored = function () {
        foreach (range(1, 5) as $i) {
            $this->n++;
            app(Referrals::class)->send($this->a->leader, $this->g, $this->cities[1], [...$this->ans, 'client_name' => "Client {$this->n}", 'client_phone' => '+14355551'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT)], true, null, true);
        }
        $this->travel(25)->hours();
        Artisan::call('t2t:referrals:expire');
    };
    $this->daily = fn () => Artisan::call('t2t:performance');
    $this->challenge = fn () => DB::table('seat_challenges')->latest('id')->first();
});

it('never challenges in the first 90 days, or without enough referrals', function () {
    ($this->ignored)();
    ($this->daily)();
    expect($this->seat->fresh()->below_since)->not->toBeNull();
    $this->travel(61)->days();
    ($this->ignored)();
    ($this->daily)();
    expect(DB::table('seat_challenges')->count())->toBe(0); // still inside the first 90 days

    $this->travel(100)->days(); // old referrals have left the 90-day window: no score
    ($this->daily)();
    expect($this->seat->fresh()->below_since)->toBeNull();
});

it('runs a full challenge: 60 days below, 30 days to recover, 7 to appeal, then the seat moves', function () {
    $this->travel(100)->days();
    ($this->ignored)();
    ($this->daily)();
    $this->travel(60)->days();
    ($this->ignored)();
    ($this->daily)();
    $c = ($this->challenge)();
    expect($c->status)->toBe('open')->and((int) $c->challenger_team_id)->toBe($this->challenger->id)->and((int) $c->score_at_open)->toBe(0);
    Notification::assertSentTo($this->holder->leader, InAppNotification::class, fn ($n) => $n->type === 'seat.challenge' && str_contains($n->title, 'has been challenged') && str_contains($n->body, 'Reach 70'));
    Notification::assertSentTo($this->challenger->leader, InAppNotification::class, fn ($n) => $n->type === 'seat.challenge');
    $this->actingAs($this->holder->leader)->get('/app/performance')->assertInertia(fn ($p) => $p->where('challenges.0.isHolder', true)->where('challenges.0.status', 'open'));

    $this->travel(20)->days();
    ($this->ignored)();
    $this->travel(10)->days();
    ($this->daily)();
    expect(($this->challenge)()->status)->toBe('pending');
    $this->travel(8)->days();
    ($this->daily)();

    $seat = $this->seat->fresh();
    expect(($this->challenge)()->status)->toBe('transferred')->and($seat->primary_team_id)->toBe($this->challenger->id)
        ->and(SeatWaitlist::where(['seat_id' => $seat->id, 'team_id' => $this->holder->id])->exists())->toBeTrue()
        ->and(SeatWaitlist::where(['seat_id' => $seat->id, 'team_id' => $this->challenger->id])->exists())->toBeFalse()
        ->and(DB::table('seat_events')->where('seat_id', $seat->id)->whereIn('event', ['challenge_won', 'challenge_lost'])->count())->toBe(2);
});

it('pauses the move on appeal; the group owner decides', function () {
    $this->travel(100)->days();
    ($this->ignored)();
    ($this->daily)();
    $this->travel(60)->days();
    ($this->ignored)();
    ($this->daily)();
    $this->travel(20)->days();
    ($this->ignored)();
    $this->travel(10)->days();
    ($this->daily)();
    $id = ($this->challenge)()->id;

    $this->actingAs($this->challenger->leader)->post("/app/challenges/{$id}/appeal", ['reason' => 'Not mine to appeal', 'choice' => 'leave'])->assertForbidden();
    $this->actingAs($this->holder->leader)->post("/app/challenges/{$id}/appeal", ['reason' => 'A family emergency; I was away without booking time off.', 'choice' => 'backup'])->assertSessionHas('status', 'challenge-appealed');
    Notification::assertSentTo($this->owner, InAppNotification::class, fn ($n) => $n->type === 'seat.challenge' && str_contains($n->title, 'Appeal'));
    $this->travel(8)->days();
    ($this->daily)();
    expect($this->seat->fresh()->primary_team_id)->toBe($this->holder->id);

    $this->actingAs(User::factory()->create())->get('/app/group/solar-installers-network/challenges')->assertForbidden();
    $this->actingAs($this->owner)->get('/app/group/solar-installers-network/challenges')->assertInertia(fn ($p) => $p->component('app/group/Challenges')->where('challenges.0.status', 'appealed'));
    $this->post("/app/challenges/{$id}/decide", ['uphold' => false])->assertSessionHasErrors('note');
    $this->post("/app/challenges/{$id}/decide", ['uphold' => false, 'note' => 'Documented emergency; the seat stays.'])->assertSessionHas('status', 'challenge-overturned');
    expect(($this->challenge)()->status)->toBe('overturned')->and($this->seat->fresh()->primary_team_id)->toBe($this->holder->id)->and($this->seat->fresh()->below_since)->toBeNull();
});

it('pauses the challenge clock during time off, and lets admins cancel a challenge', function () {
    $this->travel(100)->days();
    ($this->ignored)();
    ($this->daily)();
    $since = $this->seat->fresh()->below_since->toDateString();
    DB::table('team_time_off')->insert(['team_id' => $this->holder->id, 'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    $this->travel(2)->days();
    ($this->daily)();
    expect($this->seat->fresh()->below_since->toDateString())->toBe(now()->parse($since)->addDay()->toDateString());

    $this->travel(60)->days();
    ($this->ignored)();
    ($this->daily)();
    $id = ($this->challenge)()->id;
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($this->owner)->post("/app/challenges/{$id}/cancel", ['note' => 'x'])->assertForbidden();
    $this->actingAs($admin)->post("/app/challenges/{$id}/cancel", ['note' => 'Data issue during the outage'])->assertSessionHas('status', 'challenge-cancelled');
    expect(($this->challenge)()->status)->toBe('cancelled')->and($this->seat->fresh()->primary_team_id)->toBe($this->holder->id);
});
