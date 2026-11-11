<?php

use App\Domains\Community\Badges\Badges;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Credits;
use App\Domains\Referrals\Fees;
use App\Domains\Referrals\Introductions;
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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->re = Group::where('slug', 'residential-agent-alliance')->firstOrFail();      // Tier A
    $this->mort = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();       // Tier B
    $this->movers = Group::where('slug', 'movers-network')->firstOrFail();              // Tier A
    $s = app(Seats::class);
    $mk = function (string $n, Group $g, int $city) use ($s) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
        $s->approve($s->apply($g, $t, $this->cities[$city], 'L', null), null);
        DB::table('team_licenses')->insert(['team_id' => $t->id, 'industry_id' => $g->industry_id, 'state_id' => DB::table('states')->value('id'), 'number' => 'L'.$t->id, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);

        return $t;
    };
    $this->agent = $mk('Desert Realty', $this->re, 0);
    $this->lender = $mk('Summit Loans', $this->mort, 1);
    $this->mover = $mk('Red Rock Movers', $this->movers, 1);
    // Each network has its counsel-approved agreement, as it would in production.
    $ag = app(App\Domains\Network\Industries\Agreements\Agreements::class);
    foreach ([$this->re, $this->mort, $this->movers] as $g) {
        $ag->save($g->industry, $ag->current($g->industry)['clauses'], null);
        $ag->approve($g->industry, null, 'J. Counsel', 'ok');
    }
    $this->owner = User::factory()->create();
    $this->movers->owners()->attach($this->owner->id);
    $this->actingAs($this->owner)->post('/app/group/movers-network/fee', ['type' => 'percent', 'value' => 20, 'due_days' => 10]);
    $this->answers = fn (Group $g) => collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
        ->reject(fn ($f) => in_array($f['key'], ['client_name', 'client_phone', 'client_email'], true))
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $this->n = 0;
    $this->intro = function (Team $from, Group $fromGroup, Group $to, int $city = 1) {
        $this->n++;

        return app(Referrals::class)->send($from->leader, $to, $this->cities[$city], [...($this->answers)($to), 'client_name' => 'Tom Brennan', 'client_phone' => '+143555508'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true, false, $fromGroup)['referral'];
    };
});

it('[1][2] introduces a client to another industry’s seat holder in the same city, through the normal referral flow', function () {
    $r = ($this->intro)($this->agent, $this->re, $this->mort);
    expect($r->receiver_team_id)->toBe($this->lender->id)->and($r->introduced_from_group_id)->toBe($this->re->id)->and($r->group_id)->toBe($this->mort->id);
    app(Referrals::class)->accept($r->fresh(), $this->lender->leader);
    app(Pipeline::class)->move($r->fresh(), $this->lender->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 300000, 'earnings' => 3000]);
    app(Closing::class)->confirm($r->fresh(), $this->agent->leader);
    expect($r->fresh()->close_status)->toBe('confirmed');
    $this->actingAs($this->lender->leader)->get('/app/inbox')->assertOk();
    // A member can only introduce from a network they belong to.
    expect(fn () => ($this->intro)($this->agent, $this->movers, $this->mort))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('[3] allows a fee only when both industries allow fees, and writes the agreement to match', function () {
    $text = fn (Referral $r) => implode(' ', array_column((array) json_decode((string) DB::table('referral_agreements')->where('referral_id', $r->id)->value('sections'), true), 'text'));
    $toMovers = ($this->intro)($this->agent, $this->re, $this->movers);            // A → A
    app(Referrals::class)->accept($toMovers->fresh(), $this->mover->leader);
    expect(Fees::forReferral($toMovers->fresh()))->toBe(['type' => 'percent', 'value' => 2000])
        ->and($text($toMovers))->toContain('20% of the gross earnings');

    $lenderAgent = Team::forLeader(User::factory()->create());
    app(Seats::class)->approve(app(Seats::class)->apply($this->mort, $lenderAgent, $this->cities[2], 'L', null), null);
    $intoAgent = ($this->intro)($lenderAgent, $this->mort, $this->re, 0);          // B → A: RESPA, no fee either way
    app(Referrals::class)->accept($intoAgent->fresh(), $this->agent->leader);
    expect(Fees::forReferral($intoAgent->fresh()))->toBeNull()->and($text($intoAgent))->not->toBe('')->not->toContain('will pay the referring party');
    expect((string) DB::table('referral_agreements')->where('referral_id', $intoAgent->id)->value('summary'))->toContain('No money changes hands');
});

it('[4] DONE WHEN: an introduction into a restricted industry cannot carry a fee, but does award credits', function () {
    $r = ($this->intro)($this->agent, $this->re, $this->mort);
    app(Referrals::class)->accept($r->fresh(), $this->lender->leader);
    app(Pipeline::class)->move($r->fresh(), $this->lender->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 300000, 'earnings' => 3000]);
    app(Closing::class)->confirm($r->fresh(), $this->agent->leader);
    expect(Fees::forReferral($r->fresh()))->toBeNull()->and(DB::table('referral_fees')->count())->toBe(0)
        ->and(Credits::balance($this->agent))->toBe(1 + 2)->and(Credits::balance($this->lender))->toBe(-1);
    $this->actingAs($this->agent->leader)->post("/app/referrals/{$r->ulid}/fee", ['type' => 'flat', 'value' => 500])->assertSessionHasErrors('value');
});

it('[5][7] suggests the networks often needed together, who holds each seat, and whether a fee can apply', function () {
    $r = ($this->intro)($this->agent, $this->re, $this->movers);
    $this->actingAs($this->agent->leader)->get('/app/introduce?referral='.$r->ulid)->assertInertia(fn ($p) => $p->component('app/Introduce')
        ->where('city.id', $this->cities[1]->id)->where('client.name', 'Tom Brennan')
        ->where('suggestions', function ($s) {
            $s = collect($s);
            $m = $s->firstWhere('slug', 'mortgage-lenders-circle');
            $mv = $s->firstWhere('slug', 'movers-network');

            return $m['team'] === 'Summit Loans' && $m['fee'] === false && str_contains($m['explanation'], 'credited introduction')
                && $mv['team'] === 'Red Rock Movers' && $mv['fee'] === true && ! $s->contains('slug', 'residential-agent-alliance');
        }));
    $this->actingAs($this->lender->leader)->get("/app/referrals/{$r->ulid}")->assertNotFound(); // not a party: its existence isn't revealed
});

it('[6] introduces one client to several industries at once, with consent naming each business', function () {
    $base = ['from' => 'residential-agent-alliance', 'city_id' => $this->cities[1]->id, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355559999'];
    $intros = [['group' => 'mortgage-lenders-circle', 'answers' => ($this->answers)($this->mort), 'consent' => true], ['group' => 'movers-network', 'answers' => ($this->answers)($this->movers)]];
    $this->actingAs($this->agent->leader)->post('/app/introduce', [...$base, 'intros' => $intros])->assertSessionHasErrors('intros.1.consent');
    $intros[1]['consent'] = true;
    $this->post('/app/introduce', [...$base, 'intros' => $intros])->assertRedirect('/app/sent')->assertSessionHas('status', 'introductions-sent');
    expect(Referral::whereNotNull('introduced_from_group_id')->pluck('receiver_team_id')->sort()->values()->all())->toBe(collect([$this->lender->id, $this->mover->id])->sort()->values()->all());
});

it('[8] awards the Connector badge for three accepted introductions', function () {
    foreach (range(1, 3) as $i) {
        $r = ($this->intro)($this->agent, $this->re, $this->mort);
        app(Referrals::class)->accept($r->fresh(), $this->lender->leader);
    }
    Badges::recheck(revoke: false);
    expect(collect(Badges::of($this->agent))->pluck('key'))->toContain('connector');
    $this->actingAs($this->agent->leader)->get('/app/badges')->assertInertia(fn ($p) => $p->where('badges', fn ($b) => collect($b)->contains('key', 'connector')));
});

it('[9] lets admins block a pairing with a counsel note; blocked pairs are neither suggested nor sendable', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($this->agent->leader)->post('/admin/pairings', [])->assertForbidden();
    $this->actingAs($admin)->post('/admin/pairings', ['from' => $this->re->industry_id, 'to' => $this->movers->industry_id, 'allowed' => false])->assertSessionHasErrors('note');
    $this->post('/admin/pairings', ['from' => $this->re->industry_id, 'to' => $this->movers->industry_id, 'allowed' => false, 'note' => 'Counsel review pending (state licensing)'])->assertSessionHas('status', 'pairing-saved');
    expect(collect(Introductions::suggestions($this->re, $this->cities[1]->id))->pluck('slug'))->not->toContain('movers-network');
    expect(fn () => ($this->intro)($this->agent, $this->re, $this->movers))->toThrow(Illuminate\Validation\ValidationException::class);
    $this->get('/admin/pairings')->assertInertia(fn ($p) => $p->component('admin/Pairings')->where('rules.0.allowed', false));
});

it('[10] counts cross-industry credits in the overall balance and labels them in the history', function () {
    $r = ($this->intro)($this->agent, $this->re, $this->mort);
    app(Referrals::class)->accept($r->fresh(), $this->lender->leader);
    expect(Credits::giveGet($this->agent))->toBe(['given' => 1, 'received' => 0, 'balance' => 1]);
    $this->actingAs($this->agent->leader)->get('/app/credits')->assertInertia(fn ($p) => $p->where('entries.0.crossIndustry', true)->where('entries.0.group', 'Mortgage Lenders Circle'));
});
