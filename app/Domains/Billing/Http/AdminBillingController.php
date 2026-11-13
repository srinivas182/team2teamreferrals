<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http;

use App\Domains\Billing\BillingAccounts;
use App\Domains\Billing\PricingRules;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Platform\Payments\Stripe;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Billing: revenue, free months, failed payments, platform fees, coupons, switches, prices and refunds. */
final class AdminBillingController
{
    public function index(Request $request): Response
    {
        $paid = fn ($from) => (int) DB::table('invoices')->where('status', 'paid')->where('paid_at', '>=', $from)->sum('total_cents')
            - (int) DB::table('refunds')->where('created_at', '>=', $from)->sum('amount_cents');
        $freeThisMonth = DB::table('invoices')->where('period', now()->subMonthNoOverflow()->format('Y-m'))->pluck('lines')
            ->sum(fn ($l) => count(array_filter((array) json_decode((string) $l, true), fn ($line) => is_array($line) && ! empty($line['free_reason']))));
        $owner = fn ($i) => (string) ($i->owner_type === 'company' ? DB::table('companies')->where('id', $i->owner_id)->value('name') : DB::table('teams')->where('id', $i->owner_id)->value('name'));
        $s = app(Settings::class);

        return Inertia::render('admin/Billing', [
            'stats' => [
                'revenueMonth' => $paid(now()->startOfMonth()) / 100, 'revenueYear' => $paid(now()->startOfYear()) / 100, 'freeMonthsLastRun' => $freeThisMonth,
                'failed' => DB::table('invoices')->whereIn('status', ['failed', 'action_required'])->count(), 'needNewCard' => DB::table('billing_customers')->where('needs_new_card', true)->count(),
                'platformFees' => (int) DB::table('referral_fees')->where('platform_fee_status', 'paid')->sum('platform_fee_cents') / 100,
            ],
            'failed' => DB::table('invoices')->whereIn('status', ['failed', 'action_required'])->orderBy('created_at')->limit(50)->get()
                ->map(fn ($i) => ['number' => (string) $i->number, 'payer' => $owner($i), 'total' => $i->total_cents / 100, 'attempts' => (int) $i->attempts, 'failure' => $i->failure]),
            'invoices' => DB::table('invoices')->where('status', 'paid')->orderByDesc('paid_at')->limit(50)->get()
                ->map(fn ($i) => ['id' => (int) $i->id, 'number' => (string) $i->number, 'payer' => $owner($i), 'total' => $i->total_cents / 100,
                    'refunded' => (int) DB::table('refunds')->where('invoice_id', $i->id)->sum('amount_cents') / 100]),
            'coupons' => DB::table('coupons')->orderByDesc('id')->get(['id', 'code', 'percent_off', 'amount_off_cents', 'months', 'max_redemptions', 'redeemed', 'expires_on', 'active']),
            'settings' => ['platformFeeCollection' => PricingRules::platformFeeCollectionOn(), 'stripeTax' => PricingRules::stripeTaxOn(),
                'premiumPrice' => PricingRules::price('premium'), 'inviteRewardMonths' => \App\Domains\Network\Invites\InviteRewards::setting('reward_months'),
                'inviteCap' => \App\Domains\Network\Invites\InviteRewards::setting('cap'), 'ownerPerSeats' => \App\Domains\Network\Invites\InviteRewards::setting('owner_per_seats'), 'privateNetworkPrice' => PricingRules::price('private_network'), 'stripeTaxSetting' => $s->get('billing.stripe_tax', '0')],
            'isSuperAdmin' => $this->user($request)->hasRole('super_admin'),
            'status' => session('status'),
            'seo' => SeoData::private('Billing')->toArray(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $d = $request->validate(['platform_fee_collection' => ['sometimes', 'boolean'], 'stripe_tax' => ['sometimes', 'boolean'],
            'premium_price' => ['sometimes', 'numeric', 'min:0', 'max:10000'], 'private_network_price' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'invite_reward_months' => ['sometimes', 'integer', 'between:0,12'], 'invite_cap' => ['sometimes', 'integer', 'between:0,60'], 'owner_per_seats' => ['sometimes', 'integer', 'between:1,100']]);
        // Collecting platform fees is locked until a super admin switches it on (after legal sign-off).
        if (array_key_exists('platform_fee_collection', $d) && ! $this->user($request)->hasRole('super_admin')) {
            abort(403, 'Only a super admin can switch platform fee collection.');
        }
        $s = app(Settings::class);
        foreach (['platform_fee_collection', 'stripe_tax'] as $k) {
            if (array_key_exists($k, $d)) {
                $s->set("billing.{$k}", $d[$k] ? '1' : '0');
            }
        }
        foreach (['premium_price', 'private_network_price'] as $k) {
            if (array_key_exists($k, $d)) {
                $s->set("billing.{$k}", (string) $d[$k]);
            }
        }
        \App\Domains\Content\Tokens::forget(); // website figures follow the new prices at once
        foreach (['invite_reward_months' => 'invites.reward_months', 'invite_cap' => 'invites.cap', 'owner_per_seats' => 'invites.owner_per_seats'] as $k => $key) {
            if (array_key_exists($k, $d)) {
                $s->set($key, (string) $d[$k]);
            }
        }
        activity('admin')->causedBy($request->user())->event('billing-settings')->withProperties($d)->log('Changed billing settings');

        return back()->with('status', 'billing-settings-saved');
    }

    public function createCoupon(Request $request): RedirectResponse
    {
        $d = $request->validate(['code' => ['required', 'alpha_dash', 'max:40', 'unique:coupons,code'], 'percent_off' => ['nullable', 'required_without:amount_off', 'integer', 'between:1,100'],
            'amount_off' => ['nullable', 'required_without:percent_off', 'numeric', 'min:1'], 'months' => ['required', 'integer', 'between:1,24'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'], 'expires_on' => ['nullable', 'date', 'after:today']]);
        DB::table('coupons')->insert(['code' => strtoupper((string) $d['code']), 'percent_off' => $d['percent_off'] ?? null, 'amount_off_cents' => isset($d['amount_off']) ? (int) round((float) $d['amount_off'] * 100) : null,
            'months' => $d['months'], 'max_redemptions' => $d['max_redemptions'] ?? null, 'expires_on' => $d['expires_on'] ?? null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        activity('admin')->causedBy($request->user())->event('coupon-created')->withProperties($d)->log('Created coupon '.strtoupper((string) $d['code']));

        return back()->with('status', 'coupon-created');
    }

    public function toggleCoupon(Request $request, int $coupon): RedirectResponse
    {
        DB::table('coupons')->where('id', $coupon)->update(['active' => DB::raw('not active'), 'updated_at' => now()]);

        return back()->with('status', 'coupon-updated');
    }

    /** Refund all or part of a paid invoice through Stripe; never more than was paid; reason required; audited. */
    public function refund(Request $request, int $invoice, Stripe $stripe, Notifier $notifier): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $inv = DB::table('invoices')->where('id', $invoice)->first();
        abort_unless($inv !== null && $inv->status === 'paid' && $inv->stripe_payment_intent, 422, 'Only paid invoices can be refunded.');
        $cents = (int) round((float) $d['amount'] * 100);
        $left = (int) $inv->total_cents - (int) DB::table('refunds')->where('invoice_id', $invoice)->sum('amount_cents');
        if ($cents > $left) {
            throw ValidationException::withMessages(['amount' => 'At most $'.number_format($left / 100, 2).' can still be refunded on this invoice.']);
        }
        $r = $stripe->api('POST', 'refunds', ['payment_intent' => $inv->stripe_payment_intent, 'amount' => $cents, 'metadata[invoice]' => $inv->number], "refund-{$invoice}-{$left}-{$cents}");
        DB::table('refunds')->insert(['invoice_id' => $invoice, 'amount_cents' => $cents, 'reason' => $d['reason'], 'stripe_refund_id' => $r['id'] ?? null, 'user_id' => $this->user($request)->id, 'created_at' => now()]);
        activity('admin')->causedBy($request->user())->event('refund')->withProperties(['invoice' => $inv->number, 'amount' => $cents, 'reason' => $d['reason']])->log("Refunded {$inv->number}");
        foreach (BillingAccounts::contacts((string) $inv->owner_type, (int) $inv->owner_id) as $u) {
            $notifier->send($u, 'seat.payment', ['group' => 'Billing', 'headline' => 'Refund of $'.number_format($cents / 100, 2)." on invoice {$inv->number}", 'body' => $d['reason'].' It reaches your card in 5 to 10 business days.'], url('/app/billing'));
        }

        return back()->with('status', 'refunded');
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
