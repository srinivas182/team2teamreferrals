<?php

use App\Domains\Billing\Invoicing;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
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
    $this->taxFails = false;
    $this->calls = [];
    Http::fake(['api.stripe.com/*' => function ($request) {
        $this->calls[] = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_contains($request->url(), 'tax/calculations') => $this->taxFails ? Http::response(['error' => ['message' => 'No address']], 400) : Http::response(['tax_amount_exclusive' => 160]),
            str_contains($request->url(), 'refunds') => Http::response(['id' => 're_1', 'status' => 'succeeded']),
            default => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
        };
    }]);
    $this->team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    DB::table('billing_customers')->insert(['owner_type' => 'team', 'owner_id' => $this->team->id, 'stripe_customer_id' => 'cus_1', 'payment_method_id' => 'pm_1',
        'card_brand' => 'visa', 'card_last4' => '4242', 'card_exp_month' => 1, 'card_exp_year' => 2027, 'created_at' => now(), 'updated_at' => now()]);
    $this->staff = function (string $role): User {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        if ($role === 'finance') {
            // A staff member granted billing management (not a super admin).
            $u->assignRole('staff');
            $u->givePermissionTo('billing.manage');
        } else {
            $u->assignRole($role);
        }

        return $u;
    };
});

it('bills premium from the day it starts, and a private network to its company', function () {
    $this->travelTo('2027-01-17 12:00:00');
    $this->actingAs($this->team->leader)->post('/app/billing/team/premium', ['on' => true])->assertSessionHas('status', 'premium-on');
    $admin = User::factory()->create();
    $co = Company::create(['name' => 'Sun Holdings', 'admin_id' => $admin->id]);
    Artisan::call('t2t:catalogue:sync');
    DB::table('groups')->where('slug', 'solar-installers-network')->update(['is_private' => true, 'company_id' => $co->id, 'created_at' => '2027-01-01']);
    app(Invoicing::class)->run('2027-01');
    $premium = DB::table('invoices')->where('owner_type', 'team')->first();
    expect((int) $premium->total_cents)->toBe((int) round(1900 * 15 / 31))->and($premium->lines)->toContain('Premium membership');
    expect((int) DB::table('invoices')->where('owner_type', 'company')->value('total_cents'))->toBe(4900);
});

it('adds sales tax when Stripe Tax is on, and never blocks billing if it fails', function () {
    $this->team->forceFill(['premium_since' => '2026-12-01'])->save();
    app(Settings::class)->set('billing.stripe_tax', '1');
    app(Invoicing::class)->run('2027-01');
    $inv = DB::table('invoices')->first();
    expect((int) $inv->total_cents)->toBe(1900 + 160)->and($inv->lines)->toContain('Sales tax');
    $this->taxFails = true;
    app(Invoicing::class)->run('2027-02');
    expect((int) DB::table('invoices')->latest('id')->value('total_cents'))->toBe(1900);
});

it('warns once, 30 days before a saved card expires', function () {
    $this->travelTo('2026-12-05 10:00:00');
    Artisan::call('t2t:billing:run', ['--retries' => true]);
    Artisan::call('t2t:billing:run', ['--retries' => true]);
    expect(Notification::sent($this->team->leader, InAppNotification::class)->filter(fn ($n) => str_contains($n->title, 'ending 4242 expires soon'))->count())->toBe(1);
});

it('keeps platform fee collection for super admins; billing admins manage tax, prices and coupons', function () {
    $finance = ($this->staff)('finance');
    $this->actingAs($finance)->get('/admin/billing')->assertOk()->assertInertia(fn ($p) => $p->component('admin/Billing')->where('isSuperAdmin', false));
    $this->post('/admin/billing/settings', ['platform_fee_collection' => true])->assertForbidden();
    $this->post('/admin/billing/settings', ['stripe_tax' => true, 'premium_price' => 25])->assertSessionHas('status', 'billing-settings-saved');
    expect(app(Settings::class)->get('billing.stripe_tax'))->toBe('1')->and(app(Settings::class)->get('billing.premium_price'))->toBe('25');
    $this->post('/admin/billing/coupons', ['code' => 'spring25', 'percent_off' => 25, 'months' => 3])->assertSessionHas('status', 'coupon-created');
    expect(DB::table('coupons')->value('code'))->toBe('SPRING25');
    $this->actingAs(($this->staff)('super_admin'))->post('/admin/billing/settings', ['platform_fee_collection' => true])->assertSessionHas('status', 'billing-settings-saved');
    $this->actingAs($this->team->leader)->get('/admin/billing')->assertForbidden();
});

it('refunds through Stripe, never more than was paid, with a reason the payer sees', function () {
    $this->team->forceFill(['premium_since' => '2026-12-01'])->save();
    app(Invoicing::class)->run('2027-01');
    $inv = DB::table('invoices')->first();
    $this->actingAs(($this->staff)('finance'));
    $this->post("/admin/billing/invoices/{$inv->id}/refund", ['amount' => 10])->assertSessionHasErrors('reason');
    $this->post("/admin/billing/invoices/{$inv->id}/refund", ['amount' => 25, 'reason' => 'Charged in error'])->assertSessionHasErrors('amount');
    $this->post("/admin/billing/invoices/{$inv->id}/refund", ['amount' => 10, 'reason' => 'Goodwill for the outage'])->assertSessionHas('status', 'refunded');
    expect((int) DB::table('refunds')->value('amount_cents'))->toBe(1000)->and($this->calls)->toContain('/v1/refunds');
    Notification::assertSentTo($this->team->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'Refund of $10.00'));
    $this->post("/admin/billing/invoices/{$inv->id}/refund", ['amount' => 10, 'reason' => 'Second refund too big'])->assertSessionHasErrors('amount');
});
