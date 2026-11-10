<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Performance\ServiceScore;
use App\Domains\Referrals\Pipeline;
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->b, $this->c] = [$mk('Red Rock Solar'), $mk('Hurricane Sun'), $mk('Backup Sun')];
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $s->approve($s->apply($this->g, $this->c, $this->cities[1], 'L', null), null);
    $this->ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
        $this->ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->n = 0;
    $this->send = fn (): Referral => app(Referrals::class)->send($this->a->leader, $this->g, $this->cities[1], [...$this->ans, 'client_name' => 'Client '.(++$this->n), 'client_phone' => '+143555501'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
});

it('has no score until five referrals, then scores each part from real numbers', function () {
    $R = app(Referrals::class);
    $P = app(Pipeline::class);
    foreach (range(1, 4) as $i) {
        $r = ($this->send)();
        $R->accept($r, $this->b->leader);
        $P->logCall($r->fresh(), $this->b->leader, null);
    }
    expect(ServiceScore::for($this->b)['score'])->toBeNull();
    ($this->send)(); // the fifth is never answered
    $this->travel(25)->hours();
    Artisan::call('t2t:referrals:expire');

    $s = ServiceScore::for($this->b);
    expect($s['referrals'])->toBe(5)->and($s['parts']['answered'])->toMatchArray(['done' => 4, 'of' => 5, 'points' => 20])
        ->and($s['parts']['contacted'])->toMatchArray(['done' => 4, 'of' => 4, 'points' => 25])
        ->and($s['parts']['closed'])->toMatchArray(['done' => 4, 'of' => 4])->and($s['score'])->toBe(95)
        ->and(ServiceScore::for($this->a)['referrals'])->toBe(0);
});

it('routes to the backup during time off and doesn’t count those days', function () {
    $this->actingAs($this->b->leader)->post('/app/performance/time-off', ['from' => now()->toDateString(), 'to' => now()->addDays(40)->toDateString()])->assertSessionHasErrors('to');
    $this->post('/app/performance/time-off', ['from' => now()->toDateString(), 'to' => now()->addDays(7)->toDateString(), 'reason' => 'Family holiday'])->assertSessionHas('status', 'time-off-added');
    $r = ($this->send)();
    expect($r->receiver_team_id)->toBe($this->c->id)->and(ServiceScore::for($this->b)['referrals'])->toBe(0);
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->b->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->post('/app/performance/time-off', ['from' => now()->toDateString(), 'to' => now()->toDateString()])->assertForbidden();
});

it('sends a friendly early warning below 75, at most monthly, with what helps most', function () {
    foreach (range(1, 5) as $i) {
        ($this->send)();
    }
    $this->travel(25)->hours();
    Artisan::call('t2t:referrals:expire');
    Artisan::call('t2t:performance');
    Artisan::call('t2t:performance');
    expect(ServiceScore::for($this->b)['score'])->toBe(0); // never answering scores 0, not free marks
    $warnings = Notification::sent($this->b->leader, InAppNotification::class)->filter(fn ($n) => $n->type === 'network.score');
    expect($warnings)->toHaveCount(1)->and($warnings->first()->body)->toContain('Accept or decline');
});

it('keeps one report card per team per month', function () {
    ($this->send)();
    Artisan::call('t2t:performance', ['--cards' => true]);
    Artisan::call('t2t:performance', ['--cards' => true]);
    expect(DB::table('report_cards')->where('team_id', $this->b->id)->count())->toBe(1);
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'network.report_card' && str_contains($n->title, 'report card'));
    $this->actingAs($this->b->leader)->get('/app/performance')->assertOk()->assertInertia(fn ($p) => $p->component('app/Performance')->has('cards', 1)->has('score.parts', 4));
});
