<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use App\Domains\Referrals\Trust\TrustScore;
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
    $this->sv = app(Seats::class);
    $this->mk = function (string $slug, int $city, string $name) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $g = Group::where('slug', $slug)->firstOrFail();
        $this->sv->approve($this->sv->apply($g, $t, $this->cities[$city], 'L', null), null);
        DB::table('team_licenses')->insert(['team_id' => $t->id, 'industry_id' => $g->industry_id, 'state_id' => DB::table('states')->value('id'), 'number' => 'L'.$t->id, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);

        return $t;
    };
    $this->a = ($this->mk)('solar-installers-network', 2, 'Lone Star Solar');
    $this->b = ($this->mk)('solar-installers-network', 1, 'Hurricane Sun');
    $this->n = 0;
    // A referral from $from to $to, accepted (optionally late), then optionally won (confirmed or disputed) or lost.
    $this->refer = function (Team $from, Team $to, string $slug = 'solar-installers-network', int $city = 1, bool $late = false, ?string $outcome = null) {
        $g = Group::where('slug', $slug)->firstOrFail();
        $ans = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
            ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
            }])->all();
        $this->n++;
        $r = app(Referrals::class)->send($from->leader, $g, $this->cities[$city], [...$ans, 'client_name' => 'Client '.$this->n, 'client_phone' => '+143555509'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
        app(Referrals::class)->accept($r->fresh(), $to->leader);
        if ($late) {
            DB::table('referrals')->where('id', $r->id)->update(['accepted_at' => Referral::find($r->id)->respond_by->addHour()]);
        }
        if ($outcome === 'won' || $outcome === 'disputed') {
            app(Pipeline::class)->move($r->fresh(), $to->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 20000, 'earnings' => 2000]);
            $outcome === 'won' ? app(Closing::class)->confirm($r->fresh(), $from->leader) : app(Closing::class)->dispute($r->fresh(), $from->leader, 'Not closed yet');
        } elseif ($outcome === 'lost') {
            app(Pipeline::class)->move($r->fresh(), $to->leader, 'lost', null, ['lost_reason' => 'Went elsewhere']);
        }

        return $r->fresh();
    };
});

it('shows "New" under 3 accepted referrals', function () {
    ($this->refer)($this->a, $this->b);
    ($this->refer)($this->a, $this->b);
    expect(TrustScore::compute($this->b))->toMatchArray(['score' => null, 'status' => 'new']);
    ($this->refer)($this->a, $this->b);
    expect(TrustScore::compute($this->b)['status'])->toBe('scored');
});

it('builds each part from real behaviour: on-time answers, missed updates, wins, disputed wins', function () {
    foreach ([false, false, true, false] as $late) {
        ($this->refer)($this->a, $this->b, late: $late);
    }
    $rs = Referral::where('receiver_team_id', $this->b->id)->orderBy('id')->get();
    DB::table('referral_events')->insert(['referral_id' => $rs[0]->id, 'event' => 'missed_update', 'team_id' => $this->b->id, 'created_at' => now()]);
    ($this->refer)($this->a, $this->b, outcome: 'won');
    ($this->refer)($this->a, $this->b, outcome: 'won');
    ($this->refer)($this->a, $this->b, outcome: 'disputed');
    ($this->refer)($this->a, $this->b, outcome: 'lost');
    $p = TrustScore::compute($this->b)['parts'];
    expect($p['response']['points'])->toBe(88)->and($p['response']['items'])->toBe(8)      // 7 of 8 on time
        ->and($p['updates']['points'])->toBe(88)                                           // 1 missed of 8
        ->and($p['conversion']['points'])->toBe(67)->and($p['conversion']['items'])->toBe(3) // 2 won, 1 lost
        ->and($p['accuracy']['points'])->toBe(67)                                          // 1 of 3 wins disputed
        ->and($p['fees']['points'])->toBeNull();                                           // no fees: doesn't count
});

it('counts fee reliability as payer, paid by the due date, and shares weights when a part has too few items', function () {
    $refs = collect(range(1, 4))->map(fn () => ($this->refer)($this->a, $this->b)); // one fee per referral
    foreach (range(1, 4) as $i) {
        DB::table('referral_fees')->insert(['referral_id' => $refs[$i - 1]->id, 'payer_team_id' => $this->b->id, 'payee_team_id' => $this->a->id, 'type' => 'flat', 'rate' => 0, 'basis_cents' => 0,
            'amount_cents' => 10000, 'due_on' => now()->subDays(10)->toDateString(), 'status' => 'paid', 'paid_at' => $i === 4 ? now()->subDays(2) : now()->subDays(12), 'created_at' => now(), 'updated_at' => now()]);
    }
    $t = TrustScore::compute($this->b);
    expect($t['parts']['fees']['points'])->toBe(75);
    $only = TrustScore::compute($this->b, null, ['response' => 0, 'updates' => 0, 'conversion' => 0, 'fees' => 1, 'accuracy' => 0]);
    expect($only['score'])->toBe(75); // conversion and accuracy have no items: the weight goes to what counts
});

it('Tier B has no fee part, and recent slips count more than old ones', function () {
    $x = ($this->mk)('mortgage-lenders-circle', 0, 'Lakeside Lending');
    $y = ($this->mk)('mortgage-lenders-circle', 1, 'Summit Loans');
    foreach (range(1, 3) as $i) {
        ($this->refer)($x, $y, 'mortgage-lenders-circle');
    }
    expect(TrustScore::compute($y)['parts']['fees']['points'])->toBeNull();
    // An old late answer weighs less than a recent one.
    foreach (range(1, 3) as $i) {
        ($this->refer)($this->a, $this->b);
    }
    $late = ($this->refer)($this->a, $this->b, late: true);
    $recent = TrustScore::compute($this->b)['parts']['response']['points'];
    DB::table('referrals')->where('id', $late->id)->update(['created_at' => now()->subDays(300)]);
    expect(TrustScore::compute($this->b)['parts']['response']['points'])->toBeGreaterThan($recent);
});

it('recomputes nightly, keeping history, and shows the score on profiles, the directory, Tier A/C boards and Performance', function () {
    foreach (range(1, 3) as $i) {
        ($this->refer)($this->a, $this->b);
    }
    Artisan::call('t2t:trust');
    $score = TrustScore::stored($this->b->id)['score'];
    expect($score)->toBeInt()->and(DB::table('trust_score_history')->where('team_id', $this->b->id)->count())->toBe(1);
    Artisan::call('schedule:list');
    expect(Artisan::output())->toMatch('/30\s+2\s+\*\s+\*\s+\*\s+php artisan t2t:trust/');
    $this->actingAs($this->a->leader)->get('/t/'.$this->b->fresh()->slug)->assertInertia(fn ($p) => $p->where('record.trust.score', $score));
    $this->get('/app/directory')->assertInertia(fn ($p) => $p->where('teams', fn ($t) => collect($t)->firstWhere('name', 'Hurricane Sun')['trust'] === $score));
    $this->get('/app/leaderboards')->assertInertia(fn ($p) => $p->where('allTime', fn ($rows) => collect($rows)->firstWhere('team', 'Hurricane Sun')['trust'] === $score));
    $this->actingAs($this->b->leader)->get('/app/performance')->assertInertia(fn ($p) => $p->where('trust.score', $score)->where('trust.parts.response.label', 'Response speed'));
    $x = ($this->mk)('mortgage-lenders-circle', 0, 'Lakeside Lending');
    $this->actingAs($x->leader)->get('/app/leaderboards?group=mortgage-lenders-circle')->assertInertia(fn ($p) => $p->where('allTime', fn ($rows) => collect($rows)->every(fn ($r) => ! array_key_exists('trust', (array) $r))));
});

it('lets admins preview weights without saving, then save with an audit; members can’t', function () {
    foreach (range(1, 3) as $i) {
        ($this->refer)($this->a, $this->b, late: $i === 1);
    }
    Artisan::call('t2t:trust');
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $w = ['response' => 80, 'updates' => 5, 'conversion' => 5, 'fees' => 5, 'accuracy' => 5];
    $this->actingAs($this->b->leader)->post('/admin/trust', ['weights' => $w])->assertForbidden();
    $this->actingAs($admin)->post('/admin/trust/preview', ['weights' => $w])->assertSessionHas('trust_preview', fn ($p) => $p['result']['teams'] >= 1 && $p['result']['down'] >= 1);
    expect(TrustScore::weights()['response'])->toBe(25); // nothing saved
    $this->post('/admin/trust', ['weights' => array_map(fn () => 0, $w)])->assertSessionHasErrors('weights');
    $this->post('/admin/trust', ['weights' => $w])->assertSessionHas('status', 'trust-weights-saved');
    expect(TrustScore::weights()['response'])->toBe(80)->and(DB::table('activity_log')->where('event', 'trust-weights')->exists())->toBeTrue();
    $this->get('/admin/trust')->assertInertia(fn ($p) => $p->component('admin/Trust')->where('weights.response', 80));
});

it('puts Admin → AI and Trust score in the admin menu', function () {
    $menu = (string) file_get_contents(resource_path('js/layouts/AdminLayout.tsx'));
    expect($menu)->toMatch("/href: '\\/admin\\/ai', icon: \\w+, permission: 'settings\\.manage', ready: true/")
        ->toMatch("/href: '\\/admin\\/trust', icon: \\w+, permission: 'settings\\.manage', ready: true/");
});
