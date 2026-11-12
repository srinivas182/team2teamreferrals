<?php

use App\Domains\Billing\RevenueTypes;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Dashboard\NetworkKpis;
use App\Domains\Referrals\Dashboard\Period;
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
    $ans = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $this->refs = collect(range(1, 3))->map(fn ($i) => app(Referrals::class)->send($this->a->leader, $g, $cities[1], [...$ans, 'client_name' => "Maria Lopez{$i}", 'client_phone' => '+14355550'.(180 + $i), 'client_email' => "maria{$i}@clients.test"], true, null, true)['referral']);
    app(Referrals::class)->accept($this->refs[0]->fresh(), $this->b->leader);
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('super_admin');
    $this->support = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->support->assignRole(Permissions::STAFF);
    $this->support->givePermissionTo('referrals.view');
    $this->invoice = fn (array $lines, string $paidAt) => DB::table('invoices')->insert(['number' => 'INV-'.uniqid(), 'owner_type' => 'team', 'owner_id' => $this->a->id, 'period' => '2026-11',
        'lines' => json_encode($lines), 'total_cents' => array_sum(array_column($lines, 'amount_cents')), 'status' => 'paid', 'paid_at' => $paidAt, 'created_at' => now(), 'updated_at' => now()]);
});

it('[1] shows network numbers that each equal their records', function () {
    $p = Period::from('last_30');
    foreach (array_keys(NetworkKpis::KPIS) as $key) {
        $rows = NetworkKpis::rows($key, $p);
        $expected = $key === 'conversion' ? ($rows === [] ? null : (int) round(100 * count(array_filter($rows, fn ($r) => $r['status'] === 'won')) / count($rows))) : count($rows);
        expect(NetworkKpis::value($key, $p))->toBe($expected, $key);
    }
    expect(NetworkKpis::value('referrals', $p))->toBe(3)->and(NetworkKpis::value('accepted', $p))->toBe(1)->and(NetworkKpis::value('seats_filled', $p))->toBe(2);
    $this->actingAs($this->admin)->get('/admin/network')->assertInertia(fn ($pg) => $pg->component('admin/Network')->where('top.groups.0.name', 'Solar Installers Network')->where('revenue', fn ($r) => $r !== null));
    $this->get('/admin/network/kpi/referrals')->assertInertia(fn ($pg) => $pg->has('rows', 3));
});

it('classifies revenue by type the same for old and new invoices, nets refunds, and keeps tax and team-to-team fees out', function () {
    $old = [['seat_id' => 1, 'description' => 'Moab · Solar', 'amount_cents' => 1000], ['description' => 'Premium membership · Nov 2026', 'amount_cents' => 1900],
        ['ai_purchase_id' => 4, 'description' => 'AI credits: 500 credits', 'amount_cents' => 1000], ['redemption_id' => 2, 'description' => 'Coupon WELCOME', 'amount_cents' => -500],
        ['description' => 'Sales tax', 'tax' => true, 'amount_cents' => 300], ['credit' => true, 'description' => 'Account credit', 'amount_cents' => -200]];
    $new = array_map(fn ($l) => [...$l, 'type' => RevenueTypes::classify($l)], $old);
    expect(array_map([RevenueTypes::class, 'classify'], $old))->toBe(['seat', 'premium', 'ai_credits', 'discount', 'tax', 'account_credit'])
        ->and(array_map([RevenueTypes::class, 'classify'], $new))->toBe(array_map([RevenueTypes::class, 'classify'], $old));
    ($this->invoice)($old, now()->subDays(2)->toDateTimeString());
    DB::table('refunds')->insert(['invoice_id' => DB::table('invoices')->value('id'), 'amount_cents' => 400, 'reason' => 'Goodwill', 'user_id' => $this->admin->id, 'created_at' => now()]);
    DB::table('referral_fees')->insert(['referral_id' => $this->refs[0]->id, 'payer_team_id' => $this->b->id, 'payee_team_id' => $this->a->id, 'type' => 'flat', 'rate' => 0, 'basis_cents' => 0, 'amount_cents' => 99999,
        'due_on' => now()->toDateString(), 'status' => 'received', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $s = RevenueTypes::summary(now()->subDays(30)->toDateTimeString(), now()->toDateTimeString());
    expect($s['gross'])->toBe(3900)->and($s['adjustments'])->toBe(-700)->and($s['refunds'])->toBe(400)->and($s['net'])->toBe(2800)->and($s['tax'])->toBe(300);
    $this->actingAs($this->admin)->get('/admin/revenue')->assertInertia(fn ($p) => $p->where('revenue.net', 2800)->where('invoices.0.payer', 'Lone Star Solar'));
    expect($this->get('/admin/revenue/export.csv')->streamedContent())->toContain('"Seat subscriptions",10.00,0.00');
});

it('[7] gives support staff the CRM but not revenue', function () {
    $this->actingAs($this->support)->get('/admin/referrals')->assertOk();
    $this->get('/admin/network')->assertInertia(fn ($p) => $p->where('revenue', null));
    $this->get('/admin/revenue')->assertForbidden();
    $this->actingAs($this->a->leader)->get('/admin/referrals')->assertForbidden();
});

it('[2][6] filters the CRM, shortens client names, and reveals details only with a logged reason', function () {
    $this->actingAs($this->support);
    $this->get('/admin/referrals?group=solar-installers-network&stage=accepted')->assertInertia(fn ($p) => $p->component('admin/NetworkCrm')->where('total', 1)->where('rows.0.receiver', 'Hurricane Sun'));
    $this->get('/admin/referrals?sender=Lone%20Star')->assertInertia(fn ($p) => $p->where('total', 3)->where('rows.0.client', fn ($c) => ! str_contains((string) $c, 'Lopez')));
    $this->get('/admin/referrals?state=ZZ')->assertInertia(fn ($p) => $p->where('total', 0));
    $this->get('/admin/referrals?from='.now()->addDay()->toDateString())->assertInertia(fn ($p) => $p->where('total', 0));
    $csv = $this->get('/admin/referrals/export.csv')->streamedContent();
    expect($csv)->toContain('Hurricane Sun')->not->toContain('Lopez1')->not->toContain('555-0')->not->toContain('clients.test');
    $ulid = $this->refs[0]->ulid;
    $this->post("/admin/referrals/{$ulid}/reveal", ['reason' => 'short'])->assertSessionHasErrors('reason');
    $this->post("/admin/referrals/{$ulid}/reveal", ['reason' => 'Support ticket 4411: client asked to update email'])->assertSessionHas('revealed', fn ($r) => $r['name'] === 'Maria Lopez1' && $r['email'] === 'maria1@clients.test');
    expect(DB::table('activity_log')->where('event', 'client-details-viewed')->value('properties'))->toContain('Support ticket 4411');
});

it('[12] opens every admin menu link for a super admin', function () {
    preg_match_all("/href: '(\\/admin[^']*)'[^}]*ready: true/", (string) file_get_contents(resource_path('js/layouts/AdminLayout.tsx')), $m);
    $broken = [];
    foreach (array_unique($m[1]) as $href) {
        $status = $this->actingAs($this->admin)->get($href)->getStatusCode();
        if (! in_array($status, [200, 302], true)) {
            $broken[] = "{$href} ({$status})";
        }
    }
    expect($m[1])->not->toBeEmpty()->and($broken)->toBe([]);
});
