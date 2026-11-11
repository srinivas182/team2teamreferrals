<?php

use App\Domains\Community\Badges\Badges;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Pipeline;
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
    $this->seats = app(Seats::class);
    $training = app(App\Domains\Network\Training\Training::class);
    // Each test team holds a seat and has passed its network training (the gate applies after the grace period).
    $mk = fn (string $n, Group $g, int $city) => tap(tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]), function ($t) use ($g, $city, $training) {
        $this->seats->approve($this->seats->apply($g, $t, $this->cities[$city], 'L', null), null);
        $module = $training->module($g->industry);
        $training->submit($t->leader, $module, array_column(json_decode((string) $module->questions, true), 'answer'));
    });
    $this->mk = $mk;
    [$this->a, $this->b, $this->c] = [$mk('Red Rock Solar', $this->g, 0), $mk('Hurricane Sun', $this->g, 1), $mk('Canyon Power', $this->g, 2)];
    $this->n = 0;
    $this->refer = function (Team $from, Team $to, int $city, bool $won = false, ?Group $g = null) {
        $g ??= $this->g;
        $ans = [];
        foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
            $ans[$f['key']] = match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
            };
        }
        $this->n++;
        $r = app(Referrals::class)->send($from->leader, $g, $this->cities[$city], [...$ans, 'client_name' => 'Client '.$this->n, 'client_phone' => '+143555507'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
        app(Referrals::class)->accept($r->fresh(), $to->leader);
        if ($won) {
            app(Pipeline::class)->move($r->fresh(), $to->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 20000, 'earnings' => 1000]);
            app(Closing::class)->confirm($r->fresh(), $from->leader);
        }

        return $r->fresh();
    };
});

it('[1] lets teams set specialties and languages, and finds members by city, specialty, language and badge', function () {
    $this->actingAs($this->b->leader)->get('/app/team')->assertInertia(fn ($p) => $p->where('specialtyOptions', fn ($o) => collect($o)->contains('Residential') && collect($o)->contains('Other')));
    $this->post('/app/team', ['name' => 'Hurricane Sun', 'specialties' => ['Residential', 'Energy efficiency'], 'languages' => ['English', 'Spanish']])->assertRedirect();
    $this->post('/app/team', ['name' => 'Hurricane Sun', 'languages' => ['Klingon']])->assertSessionHasErrors('languages.0');
    DB::table('member_badges')->insert(['team_id' => $this->b->id, 'badge' => 'fast_responder', 'awarded_at' => now()]);
    $this->actingAs($this->a->leader);
    $this->get('/app/directory')->assertInertia(fn ($p) => $p->component('app/Directory')->has('teams', 3));
    $this->get('/app/directory?language=Spanish&specialty=Residential&badge=fast_responder')->assertInertia(fn ($p) => $p->has('teams', 1)->where('teams.0.name', 'Hurricane Sun'));
    $this->get('/app/directory?city='.urlencode($this->cities[2]->name))->assertInertia(fn ($p) => $p->has('teams', 1)->where('teams.0.name', 'Canyon Power'));
    $this->get('/t/'.$this->b->fresh()->slug)->assertInertia(fn ($p) => $p->where('team.languages', ['English', 'Spanish'])->where('record.badges.0.name', 'Fast Responder'));
    $this->actingAs(User::factory()->create())->get('/app/directory')->assertInertia(fn ($p) => $p->has('teams', 0)); // only your own networks
});

it('[2] awards Fast Responder, Top Closer, Top Referrer and Perfect Updates from activity', function () {
    foreach (range(1, 5) as $i) {
        ($this->refer)($this->a, $this->b, 1, won: $i <= 3); // B accepts straight away, wins 3 of 5, never misses an update
    }
    ($this->refer)($this->c, $this->a, 0);
    ($this->refer)($this->b, $this->a, 0);
    Badges::recheck();
    $held = fn (Team $t) => collect(Badges::of($t))->pluck('key')->sort()->values()->all();
    expect($held($this->b))->toBe(['fast_responder', 'perfect_updates', 'top_closer'])->and($held($this->a))->toContain('top_referrer');
});

it('[3][4] runs Top giver and Network builder through the same engine, each with how-to-earn and how-to-keep rules', function () {
    foreach (range(1, 3) as $i) {
        ($this->refer)($this->a, $this->b, 1);
    }
    ($this->refer)($this->c, $this->b, 1);
    $d = ($this->mk)('Desert Solar', $this->g, 3);
    ($this->refer)($d, $this->b, 1);
    $this->travel(1)->months();
    Badges::recheck();
    expect(collect(Badges::of($this->a))->firstWhere('key', 'top_giver'))->toBe(['key' => 'top_giver', 'name' => 'Top giver', 'group' => 'Solar Installers Network']);
    $this->actingAs($this->a->leader)->get('/app/badges')->assertInertia(fn ($p) => $p->component('app/Badges')->has('badges', 6)
        ->where('badges.0.how', fn ($h) => str_contains((string) $h, '2 hours'))->where('badges.0.keep', fn ($k) => str_contains((string) $k, '1st of each month'))->where('mine.0.key', 'top_giver'));
    foreach (Badges::ALL as $b) {
        expect($b['how'])->not->toBeEmpty()->and($b['keep'])->not->toBeEmpty();
    }
});

it('[5] re-checks badges monthly: removed when no longer earned, with history kept', function () {
    foreach (range(1, 5) as $i) {
        ($this->refer)($this->a, $this->b, 1);
    }
    Badges::recheck();
    expect(collect(Badges::of($this->b))->pluck('key'))->toContain('perfect_updates');
    $this->travel(100)->days(); // the activity has left the 90-day window
    Badges::recheck();
    expect(Badges::of($this->b))->toBe([])->and(DB::table('member_badges')->where(['team_id' => $this->b->id, 'badge' => 'perfect_updates'])->whereNotNull('revoked_at')->exists())->toBeTrue();
});

it('[6] shows a frozen monthly leaderboard and a live all-time one', function () {
    $this->travelTo(now()->startOfMonth()->addDays(5));
    ($this->refer)($this->a, $this->b, 1, won: true);
    ($this->refer)($this->a, $this->c, 2);
    ($this->refer)($this->c, $this->a, 0);
    $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->setTime(8, 0));
    Artisan::call('t2t:performance');
    ($this->refer)($this->c, $this->b, 1); // this month: not in last month's frozen board
    $this->actingAs($this->a->leader)->get('/app/leaderboards')->assertInertia(fn ($p) => $p->component('app/Leaderboards')
        ->where('monthly.rows.0.team', 'Red Rock Solar')->where('monthly.rows.0.sent', 2)->where('monthly.rows.1.sent', 1)
        ->where('allTime', fn ($rows) => collect($rows)->firstWhere('team', 'Canyon Power')['sent'] === 2));
});

it('[7] ranks restricted (Tier B) networks by service quality only, never referral counts', function () {
    $m = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $x = ($this->mk)('Lakeside Lending', $m, 0);
    $y = ($this->mk)('Summit Loans', $m, 1);
    // Restricted industries: the receiver must hold a verified licence to accept.
    DB::table('team_licenses')->insert(['team_id' => $y->id, 'industry_id' => $m->industry_id, 'state_id' => DB::table('states')->value('id'), 'number' => 'NMLS1', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
    ($this->refer)($x, $y, 1, g: $m);
    $this->actingAs($x->leader)->get('/app/leaderboards?group=mortgage-lenders-circle')->assertInertia(fn ($p) => $p->where('group.serviceOnly', true)
        ->where('allTime', fn ($rows) => collect($rows)->every(fn ($r) => ! array_key_exists('sent', (array) $r) && ! array_key_exists('closed', (array) $r) && array_key_exists('updatesOnTime', (array) $r))));
});

it('[8] sends each member a recap on the 1st: the month, the board and their badges', function () {
    foreach (range(1, 5) as $i) {
        ($this->refer)($this->a, $this->b, 1);
    }
    $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->setTime(8, 0));
    Artisan::call('t2t:performance');
    $month = now()->subMonthNoOverflow()->format('F');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'network.recap' && $n->title === "Solar Installers Network in {$month}"
        && str_contains($n->body, '5 referrals') && str_contains($n->body, 'New badges:'));
    Notification::assertSentTo($this->c->leader, InAppNotification::class, fn ($n) => $n->type === 'network.recap');
});
