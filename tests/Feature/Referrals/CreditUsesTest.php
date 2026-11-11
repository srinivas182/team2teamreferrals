<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\ClientNurtureEmail;
use App\Domains\Referrals\Credits;
use App\Domains\Referrals\Referrals;
use Illuminate\Notifications\AnonymousNotifiable;
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
    [$this->a, $this->b, $this->w] = [$mk('Red Rock Solar'), $mk('Hurricane Sun'), $mk('Waiting Solar')];
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $form = app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'];
    $this->timeline = collect($form)->firstWhere('role', 'timeline');
    $this->ans = [];
    foreach ($form as $f) {
        $this->ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->n = 0;
    $this->give = function (Team $from, Team $to, int $city, int $timeline = 0, ?string $email = null) {
        $this->n++;
        $r = app(Referrals::class)->send($from->leader, $this->g->fresh(), $this->cities[$city], [...$this->ans, $this->timeline['key'] => $this->timeline['options'][$timeline],
            'client_name' => "Tom Brennan{$this->n}", 'client_phone' => '+143555505'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT), 'client_email' => $email], true, null, true)['referral'];
        app(Referrals::class)->accept($r->fresh(), $to->leader);

        return $r->fresh();
    };
});

it('moves teams up waitlists for giving, and down for only taking', function () {
    $seat = Seat::where('territory_id', $this->cities[1]->id)->firstOrFail();
    App\Domains\Network\Seats\Models\SeatWaitlist::create(['seat_id' => $seat->id, 'team_id' => $this->w->id, 'score' => App\Domains\Network\Licenses\Licenses::rankScore($this->w), 'joined_at' => now()]);
    $before = (int) DB::table('seat_waitlist')->where('team_id', $this->w->id)->value('score');
    $s = app(Seats::class);
    $s->approve($s->apply($this->g, $this->w, $this->cities[2], 'L', null), null);
    foreach (range(1, 6) as $i) {
        ($this->give)($this->w, $this->b, 1);
    }
    expect((int) DB::table('seat_waitlist')->where(['seat_id' => $seat->id, 'team_id' => $this->w->id])->value('score'))->toBe(min(100, $before + 15));
    expect(Credits::balance($this->b))->toBe(-6); // only taking
});

it('gives generous holders extra days before a challenge', function () {
    foreach (range(1, 4) as $i) {
        ($this->give)($this->a, $this->b, 1);
    }
    expect(Credits::challengeGraceDays($this->a, $this->g->id))->toBe(0);
    ($this->give)($this->a, $this->b, 1);
    expect(Credits::challengeGraceDays($this->a, $this->g->id))->toBe(10);
});

it('awards Top giver monthly once enough teams give, and shows rank on the report card', function () {
    $s = app(Seats::class);
    $c = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Third Solar']);
    $s->approve($s->apply($this->g, $c, $this->cities[2], 'L', null), null);
    $s->approve($s->apply($this->g, $this->w, $this->cities[3], 'L', null), null);
    foreach (range(1, 3) as $i) {
        ($this->give)($this->a, $this->b, 1);
    }
    ($this->give)($c, $this->b, 1);
    ($this->give)($this->w, $this->b, 1); // three teams have given: A (3), C (1), W (1)
    expect(Credits::topGivers($this->g))->toBe([]); // this month's entries count from next month
    $this->travel(1)->months();
    expect(Credits::topGivers($this->g))->toBe([$this->a->id]);
    App\Domains\Community\Badges\Badges::recheck(); // badges are awarded at the monthly re-check
    $this->actingAs($this->a->leader)->get('/app/team/preview')->assertInertia(fn ($p) => $p->where('record.topGiverIn.0', 'Solar Installers Network'));
    Artisan::call('t2t:performance', ['--cards' => true]);
    $card = json_decode((string) DB::table('report_cards')->where('team_id', $this->a->id)->value('data'), true);
    expect($card['giveGet']['given'])->toBe(3)->and($card['rank'])->toBe(['rank' => 1, 'of' => 4]);
});

it('lists every credit with its referral and a running balance', function () {
    ($this->give)($this->a, $this->b, 1);
    ($this->give)($this->b, $this->a, 0);
    $this->actingAs($this->a->leader)->get('/app/credits')->assertInertia(fn ($p) => $p->component('app/Credits')->has('entries', 2)
        ->where('entries.0.kind', 'received')->where('entries.0.balance', 0)->where('entries.1.kind', 'sent_accepted')->where('entries.1.balance', 1)->where('entries.1.client', 'Tom B.'));
});

it('emails planning-ahead clients every 60 days with consent, and stops for good on request', function () {
    $r = ($this->give)($this->a, $this->b, 1, 2, 'tom@example.com');
    $noConsent = ($this->give)($this->a, $this->b, 1, 2, 'sam@example.com');
    $r->forceFill(['client_confirmed_at' => now()])->save();
    $this->travel(61)->days();
    Artisan::call('t2t:performance');
    Artisan::call('t2t:performance');
    $sent = Notification::sent(new AnonymousNotifiable, ClientNurtureEmail::class);
    expect($sent)->toHaveCount(1)->and($sent->first()->portal)->toContain('/r/'.$r->client_token);

    $this->get($sent->first()->stop)->assertRedirect('/r/'.$r->client_token);
    expect($r->fresh()->client_emails_stopped_at)->not->toBeNull();
    $this->get('/r/'.$r->client_token.'/stop-emails')->assertForbidden(); // must be the signed link
    $this->travel(61)->days();
    Artisan::call('t2t:performance');
    expect(Notification::sent(new AnonymousNotifiable, ClientNurtureEmail::class))->toHaveCount(1);
});

it('sends referring teams a Monday digest of their Future referrals', function () {
    ($this->give)($this->a, $this->b, 1, 2);
    $this->travelTo(now()->next('Monday')->setTime(8, 0));
    Artisan::call('t2t:performance');
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'Your Future referrals this week (1)') && str_contains($n->body, 'Tom B. with Hurricane Sun'));
    Notification::assertNotSentTo($this->b->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'Your Future referrals this week'));
});
