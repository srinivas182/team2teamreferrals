<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Analytics\Shipper;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Dashboard\Period;
use App\Domains\Referrals\Dashboard\TeamKpis;
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
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    [$this->a, $this->b] = [tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Lone Star Solar']), tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun'])];
    $sv->approve($sv->apply($g, $this->a, $cities[2], 'L', null), null);
    $sv->approve($sv->apply($g, $this->b, $cities[1], 'L', null), null);
    $owner = User::factory()->create();
    $g->owners()->attach($owner->id);
    $this->actingAs($owner)->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 20, 'due_days' => 10]);
    $ans = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $n = 0;
    $send = function (Team $from, Team $to, int $city) use ($g, $cities, $ans, &$n) {
        $n++;

        return app(Referrals::class)->send($from->leader, $g, $cities[$city], [...$ans, 'client_name' => "Maria Lopez{$n}", 'client_phone' => '+143555508'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'client_email' => "maria{$n}@example.com"], true, null, true)['referral'];
    };
    // Hurricane Sun: received 4 (accepted 3; won 1 with a fee received; lost 1), sent 1.
    $refs = collect(range(1, 4))->map(fn () => $send($this->a, $this->b, 1));
    foreach ($refs->take(3) as $r) {
        app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    }
    app(Pipeline::class)->move($refs[0]->fresh(), $this->b->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 30000, 'earnings' => 3000]);
    app(Closing::class)->confirm($refs[0]->fresh(), $this->a->leader);
    app(Pipeline::class)->move($refs[1]->fresh(), $this->b->leader, 'lost', null, ['lost_reason' => 'Went elsewhere']);
    $send($this->b, $this->a, 2);
    DB::table('referral_fees')->update(['payer_team_id' => $this->b->id, 'payee_team_id' => $this->a->id, 'due_on' => now()->subDays(3)->toDateString(), 'status' => 'due']); // owed by B, overdue
    $this->p = Period::from('last_30');
});

it('DONE WHEN (numbers): every KPI equals the records behind it', function () {
    foreach (array_keys(TeamKpis::KPIS) as $key) {
        foreach ([$this->a, $this->b] as $team) {
            $value = TeamKpis::value($key, $team, $this->p);
            $rows = TeamKpis::rows($key, $team, $this->p);
            $expected = match ($key) {
                'fees_received', 'fees_overdue' => array_sum(array_column($rows, 'amount')),
                'credits' => array_sum(array_column($rows, 'credits')),
                'conversion' => $rows === [] ? null : (int) round(100 * count(array_filter($rows, fn ($r) => $r['status'] === 'won')) / count($rows)),
                'response' => $value, // a median of the listed records' times
                default => count($rows),
            };
            expect($value)->toBe($expected, "{$key} for {$team->name}");
        }
    }
    expect(TeamKpis::value('received', $this->b, $this->p))->toBe(4)->and(TeamKpis::value('accepted', $this->b, $this->p))->toBe(3)
        ->and(TeamKpis::value('won', $this->b, $this->p))->toBe(1)->and(TeamKpis::value('conversion', $this->b, $this->p))->toBe(50)
        ->and(TeamKpis::value('fees_overdue', $this->b, $this->p))->toBe(60000)->and(TeamKpis::value('sent', $this->b, $this->p))->toBe(1);
});

it('compares with the previous period and flags small numbers', function () {
    $cards = collect(TeamKpis::cards($this->b, $this->p))->keyBy('key');
    expect($cards['received']['previous'])->toBe(0)->and($cards['received']['change'])->toBeNull()->and($cards['received']['small'])->toBeTrue()
        ->and($cards['trust']['kind'])->toBe('score');
    $this->travel(31)->days();
    $later = collect(TeamKpis::cards($this->b, Period::from('last_30')))->keyBy('key');
    expect($later['received']['value'])->toBe(0)->and($later['received']['previous'])->toBe(4)->and($later['received']['change'])->toBe(-100);
    expect(Period::from('custom', '2026-03-01', '2026-03-31')->previous()->toArray())->toMatchArray(['from' => '2026-01-29', 'to' => '2026-02-28']);
});

it('opens every number’s records, with short names and no contact details in the page or the exports', function () {
    $this->actingAs($this->b->leader);
    $this->get('/app/dashboard')->assertInertia(fn ($p) => $p->component('app/Dashboard')->has('cards', count(TeamKpis::KPIS) + 1)->where('period.preset', 'last_30'));
    foreach (array_keys(TeamKpis::KPIS) as $key) {
        $page = $this->get("/app/dashboard/kpi/{$key}?preset=last_30")->assertOk();
        $csv = $this->get("/app/dashboard/kpi/{$key}/export.csv?preset=last_30")->assertOk()->streamedContent();
        foreach ([json_encode($page->viewData('page')), $csv] as $out) {
            // Client contact details only (the page also shows the signed-in member's own email, which is fine).
            expect($out)->not->toContain('555-08')->not->toMatch('/maria\\d+@example\\.com/')->not->toContain('Lopez1');
        }
    }
    $this->get('/app/dashboard/kpi/received')->assertInertia(fn ($p) => $p->has('rows', 4)->where('rows.0.client', fn ($c) => str_starts_with((string) $c, 'Maria')));
    $this->get('/app/dashboard/kpi/nothing')->assertNotFound();
    expect($this->get('/app/dashboard/export.csv')->streamedContent())->toContain('"Referrals received",4,0,');
    $this->get('/app/dashboard/export.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('saves views, opens with the default one, and only the owner can delete them', function () {
    $this->actingAs($this->b->leader)->post('/app/dashboard/views', ['name' => 'Quarter', 'preset' => 'last_90', 'default' => true])->assertSessionHas('status', 'view-saved');
    $this->post('/app/dashboard/views', ['name' => 'Bad', 'preset' => 'custom'])->assertSessionHasErrors(['from', 'to']);
    $this->get('/app/dashboard')->assertInertia(fn ($p) => $p->where('period.preset', 'last_90')->where('views.0.name', 'Quarter')->where('views.0.default', true));
    $this->get('/app/dashboard?preset=last_7')->assertInertia(fn ($p) => $p->where('period.preset', 'last_7')); // an explicit choice wins
    $id = DB::table('dashboard_views')->value('id');
    $this->actingAs($this->a->leader)->post("/app/dashboard/views/{$id}/delete");
    expect(DB::table('dashboard_views')->count())->toBe(1);
    $this->actingAs($this->b->leader)->post("/app/dashboard/views/{$id}/delete");
    expect(DB::table('dashboard_views')->count())->toBe(0);
});

it('shows the action bar with countdown deadlines and charts from the analytics store', function () {
    app(Shipper::class)->run();
    $this->actingAs($this->b->leader)->get('/app/dashboard')->assertInertia(fn ($p) => $p
        ->where('actions', fn ($a) => collect($a)->contains(fn ($x) => $x['key'] === 'respond' && is_string($x['due'])))
        ->where('series', fn ($s) => collect($s)->sum('accepted') === 3 && collect($s)->sum('sent') === 1 && count($s) === 30));
});
