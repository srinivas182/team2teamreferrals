<?php

use App\Domains\Billing\Invoicing;
use App\Domains\Billing\PricingRules;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Storage::fake('private');
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $s = app(Settings::class);
    $s->set('payments.driver', 'stripe');
    $s->set('payments.stripe.publishable_key', 'pk_test_1');
    $s->set('payments.stripe.secret_key', 'sk_test_1', secret: true);
    $this->amounts = [];
    Http::fake(['api.stripe.com/*' => function ($request) {
        $this->amounts[] = (int) $request['amount'];

        return Http::response(['id' => 'pi_'.count($this->amounts), 'status' => 'succeeded']);
    }]);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $this->team, $this->cities[1], 'L', null), null);
    $this->seat = Seat::firstOrFail();
    $this->seat->update(['trial_ends_at' => '2027-01-01 00:00:00']);
    DB::table('billing_customers')->insert(['owner_type' => 'team', 'owner_id' => $this->team->id, 'stripe_customer_id' => 'cus_1', 'payment_method_id' => 'pm_1', 'created_at' => now(), 'updated_at' => now()]);
    $this->offer = fn (Seat $seat, string $when) => DB::table('referrals')->insert(['ulid' => strtolower((string) Str::ulid()), 'group_id' => $seat->group_id, 'seat_id' => $seat->id,
        'sender_team_id' => $seat->primary_team_id, 'sender_user_id' => $this->team->leader_id, 'receiver_team_id' => $seat->primary_team_id, 'routed_to' => 'holder', 'status' => 'sent',
        'client_name' => 'C', 'answers' => '{}', 'consent_at' => $when, 'consent_method' => 'sender_attested', 'consent_text' => 'x', 'respond_by' => $when, 'created_at' => $when, 'updated_at' => $when]);
    $this->last = fn () => DB::table('invoices')->latest('id')->first();
});

it('bills an annual plan at 10 months, starts it once paid, and credits months with no referral', function () {
    $this->actingAs($this->team->leader)->post("/app/billing/team/seats/{$this->seat->id}/cycle", ['cycle' => 'annual'])->assertSessionHas('status', 'cycle-annual');
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    app(Invoicing::class)->run('2027-01');
    $inv = ($this->last)();
    expect((int) $inv->total_cents)->toBe(1000 + 10000)->and(json_decode((string) $inv->lines, true)[1]['description'])->toContain('annual plan, Feb 2027 to Jan 2028 (2 months free)')
        ->and($this->seat->fresh()->annual_paid_until->toDateString())->toBe('2028-01-31');

    app(Invoicing::class)->run('2027-02'); // covered, and no referral offered: credited, not charged
    expect(DB::table('invoices')->count())->toBe(1)->and((int) DB::table('billing_credits')->value('amount_cents'))->toBe(833);
    app(Invoicing::class)->run('2027-02');
    expect(DB::table('billing_credits')->count())->toBe(1);
});

it('applies multi-city discounts per network', function () {
    $this->g->update(['pricing' => [...$this->g->pricing, 'discounts' => ['2' => 10, '5' => 20]]]);
    app(Seats::class)->approve(app(Seats::class)->apply($this->g->fresh(), $this->team, $this->cities[2], 'L', null), null);
    Seat::query()->update(['trial_ends_at' => '2027-01-01 00:00:00']);
    foreach (Seat::all() as $seat) {
        ($this->offer)($seat, '2027-01-10 12:00:00');
    }
    app(Invoicing::class)->run('2027-01');
    expect((int) ($this->last)()->total_cents)->toBe(1800)->and(($this->last)()->lines)->toContain('Multi-city discount: 10% for 2 seats');
});

it('applies a coupon for its months and refuses bad codes', function () {
    DB::table('coupons')->insert(['code' => 'LAUNCH50', 'percent_off' => 50, 'months' => 1, 'max_redemptions' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('coupons')->insert(['code' => 'OLD', 'percent_off' => 10, 'months' => 1, 'expires_on' => '2020-01-01', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->team->leader)->post('/app/billing/team/coupon', ['code' => 'nope'])->assertSessionHasErrors('code');
    $this->post('/app/billing/team/coupon', ['code' => 'old'])->assertSessionHasErrors('code');
    $this->post('/app/billing/team/coupon', ['code' => 'launch50'])->assertSessionHas('status', 'coupon-applied');
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    ($this->offer)($this->seat, '2027-02-10 12:00:00');
    app(Invoicing::class)->run('2027-01');
    app(Invoicing::class)->run('2027-02');
    expect(DB::table('invoices')->orderBy('id')->pluck('total_cents')->map(fn ($c) => (int) $c)->all())->toBe([500, 1000]);
    $other = Team::forLeader(User::factory()->create());
    $this->actingAs($other->leader)->post('/app/billing/team/coupon', ['code' => 'LAUNCH50'])->assertSessionHasErrors('code'); // fully used
});

it('uses account credits, never below zero', function () {
    PricingRules::addCredit('team', $this->team->id, 1500, 'Goodwill credit');
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    ($this->offer)($this->seat, '2027-02-10 12:00:00');
    app(Invoicing::class)->run('2027-01');
    expect((int) ($this->last)()->total_cents)->toBe(0)->and(($this->last)()->status)->toBe('zero');
    app(Invoicing::class)->run('2027-02');
    expect((int) ($this->last)()->total_cents)->toBe(500)->and($this->amounts)->toBe([500]);
});

it('collects platform fees on the next invoice only while switched on', function () {
    $ref = DB::table('referrals')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'group_id' => $this->g->id, 'seat_id' => $this->seat->id, 'sender_team_id' => $this->team->id, 'sender_user_id' => $this->team->leader_id,
        'routed_to' => 'holder', 'status' => 'accepted', 'client_name' => 'Tom Brennan', 'answers' => '{}', 'consent_at' => '2026-12-01', 'consent_method' => 'sender_attested', 'consent_text' => 'x', 'respond_by' => '2026-12-02', 'created_at' => '2026-12-01', 'updated_at' => '2026-12-01']);
    $payer = Team::forLeader(User::factory()->create());
    $fee = DB::table('referral_fees')->insertGetId(['referral_id' => $ref, 'payer_team_id' => $payer->id, 'payee_team_id' => $this->team->id, 'type' => 'percent', 'rate' => 3000, 'basis_cents' => 500000,
        'amount_cents' => 150000, 'platform_fee_cents' => 15000, 'platform_fee_status' => 'owed', 'due_on' => '2027-01-10', 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    app(Invoicing::class)->run('2027-01');
    expect((int) ($this->last)()->total_cents)->toBe(1000)->and(DB::table('referral_fees')->value('platform_fee_status'))->toBe('owed');

    app(Settings::class)->set('billing.platform_fee_collection', '1');
    ($this->offer)($this->seat, '2027-02-10 12:00:00');
    app(Invoicing::class)->run('2027-02');
    $inv = ($this->last)();
    expect((int) $inv->total_cents)->toBe(16000)->and($inv->lines)->toContain('Platform fee on the $1,500.00 referral fee for Tom Brennan')
        ->and(DB::table('referral_fees')->where('id', $fee)->value('platform_fee_status'))->toBe('paid');
});
