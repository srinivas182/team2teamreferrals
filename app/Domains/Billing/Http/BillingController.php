<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http;

use App\Domains\Billing\BillingAccounts;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Portal → Billing: each account this person pays for (their team, and their company if they run one). */
final class BillingController
{
    public function __construct(private readonly BillingAccounts $accounts) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user);
        $company = Company::administeredBy($user);
        $accounts = array_values(array_filter([
            $team !== null && BillingAccounts::canManage($user, 'team', $team->id) ? $this->describe('team', $team->id, $team->name) : null,
            $company !== null ? $this->describe('company', $company->id, $company->name) : null,
        ]));

        return Inertia::render('app/Billing', [
            'accounts' => $accounts,
            'stripeKey' => app(\App\Domains\Platform\Payments\Stripe::class)->connected() ? app(\App\Domains\Platform\Payments\Stripe::class)->publishableKey() : null,
            'status' => session('status'),
            'seo' => SeoData::private('Billing')->toArray(),
        ]);
    }

    public function setupIntent(Request $request, string $type): JsonResponse
    {
        $id = $this->payerId($request, $type);

        return response()->json(['clientSecret' => $this->accounts->setupIntent($type, $id)]);
    }

    public function saveCard(Request $request, string $type): RedirectResponse
    {
        $id = $this->payerId($request, $type);
        $this->accounts->saveCard($type, $id, (string) $request->validate(['payment_method' => ['required', 'string', 'starts_with:pm_', 'max:64']])['payment_method']);

        return back()->with('status', 'card-saved');
    }

    /** @return array<string, mixed> */
    private function describe(string $type, int $id, string $name): array
    {
        $acc = DB::table('billing_customers')->where(['owner_type' => $type, 'owner_id' => $id])->first();
        $seats = Seat::query()->with(['group', 'primary'])->whereNotNull('primary_team_id')->get()
            ->filter(fn (Seat $s) => BillingAccounts::payerFor($s) === ['type' => $type, 'id' => $id]);

        return [
            'premium' => $type === 'team' ? ['on' => DB::table('teams')->where('id', $id)->whereNotNull('premium_since')->exists(), 'price' => \App\Domains\Billing\PricingRules::price('premium')] : null,
            'type' => $type, 'name' => $name, 'needsNewCard' => (bool) ($acc->needs_new_card ?? false),
            'invoices' => DB::table('invoices')->where(['owner_type' => $type, 'owner_id' => $id])->orderByDesc('period')->limit(24)->get()
                ->map(fn ($i) => ['id' => (int) $i->id, 'number' => (string) $i->number, 'period' => (string) $i->period, 'total' => $i->total_cents / 100, 'status' => (string) $i->status, 'failure' => $i->failure,
                    'canPay' => in_array($i->status, ['failed', 'action_required', 'open'], true), 'clientSecret' => null]),
            'card' => $acc && $acc->payment_method_id ? ['brand' => $acc->card_brand, 'last4' => $acc->card_last4, 'exp' => sprintf('%02d/%d', $acc->card_exp_month, $acc->card_exp_year)] : null,
            'credit' => (int) DB::table('billing_credits')->where(['owner_type' => $type, 'owner_id' => $id])->selectRaw('coalesce(sum(amount_cents - used_cents), 0) as c')->value('c') / 100,
            'coupon' => DB::table('coupon_redemptions')->join('coupons', 'coupons.id', '=', 'coupon_redemptions.coupon_id')->where(['owner_type' => $type, 'owner_id' => $id])->where('months_left', '>', 0)->value('coupons.code'),
            'seats' => $seats->map(fn (Seat $s) => ['id' => $s->id, 'cycle' => $s->billing_cycle, 'paidUntil' => $s->annual_paid_until?->toDateString(), 'annual' => \App\Domains\Billing\PricingRules::annualCents($s) / 100,
                'monthsFree' => (int) data_get($s->group->pricing, 'annual_months_free', 2), 'territory' => $s->territory_name, 'group' => $s->group->name, 'team' => (string) $s->primary?->name, 'status' => $s->status, 'founding' => $s->founding,
                'price' => (float) data_get($s->group->pricing, 'seat_price', 10), 'trialEnds' => $s->trial_ends_at?->toDateString()])->values(),
        ];
    }

    /** "Pay now": charge a failed invoice immediately (reactivates seats at once when it succeeds). */
    public function payNow(Request $request, string $type, int $invoice): RedirectResponse|JsonResponse
    {
        $id = $this->payerId($request, $type);
        $inv = DB::table('invoices')->where(['id' => $invoice, 'owner_type' => $type, 'owner_id' => $id])->first();
        abort_if($inv === null, 404);
        $result = app(\App\Domains\Billing\Invoicing::class)->charge($invoice);
        if ($result === 'action_required') {
            // The bank wants the customer to confirm: hand Stripe.js the payment to authenticate.
            $pi = app(\App\Domains\Platform\Payments\Stripe::class)->api('GET', 'payment_intents/'.DB::table('invoices')->where('id', $invoice)->value('stripe_payment_intent'));

            return response()->json(['clientSecret' => $pi['client_secret'] ?? null]);
        }

        return back()->with('status', $result === 'paid' ? 'invoice-paid' : 'invoice-failed');
    }

    /** Choose monthly or annual billing for one seat (annual is billed on the next monthly invoice). */
    public function cycle(Request $request, string $type, int $seat): RedirectResponse
    {
        $id = $this->payerId($request, $type, needsStripe: false);
        $s = Seat::query()->with('primary')->findOrFail($seat);
        abort_unless(BillingAccounts::payerFor($s) === ['type' => $type, 'id' => $id], 403);
        $cycle = (string) $request->validate(['cycle' => ['required', 'in:monthly,annual']])['cycle'];
        $s->forceFill(['billing_cycle' => $cycle])->save();

        return back()->with('status', $cycle === 'annual' ? 'cycle-annual' : 'cycle-monthly');
    }

    /** Premium membership for the team (billed monthly from the day it starts; stop any time). */
    public function premium(Request $request): RedirectResponse
    {
        $id = $this->payerId($request, 'team', needsStripe: false);
        $on = $request->boolean('on');
        // The application's clock (not the database's), and only stamped when premium is first switched on.
        $on ? DB::table('teams')->where('id', $id)->whereNull('premium_since')->update(['premium_since' => now()])
            : DB::table('teams')->where('id', $id)->update(['premium_since' => null]);

        return back()->with('status', $on ? 'premium-on' : 'premium-off');
    }

    public function redeem(Request $request, string $type): RedirectResponse
    {
        $id = $this->payerId($request, $type, needsStripe: false);
        $code = strtoupper(trim((string) $request->validate(['code' => ['required', 'string', 'max:40']])['code']));
        $c = DB::table('coupons')->where('code', $code)->where('active', true)->first();
        $fail = fn (string $m) => throw \Illuminate\Validation\ValidationException::withMessages(['code' => $m]);
        if ($c === null) {
            $fail('That code isn’t valid.');
        }
        if ($c->expires_on !== null && now()->toDateString() > $c->expires_on) {
            $fail('That code has expired.');
        }
        if ($c->max_redemptions !== null && $c->redeemed >= $c->max_redemptions) {
            $fail('That code has been fully used.');
        }
        if (DB::table('coupon_redemptions')->where(['owner_type' => $type, 'owner_id' => $id])->where('months_left', '>', 0)->exists()) {
            $fail('A coupon is already active on this account.');
        }
        DB::table('coupon_redemptions')->insertOrIgnore(['coupon_id' => $c->id, 'owner_type' => $type, 'owner_id' => $id, 'months_left' => $c->months, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('coupons')->where('id', $c->id)->increment('redeemed');

        return back()->with('status', 'coupon-applied');
    }

    public function invoicePdf(Request $request, string $type, int $invoice): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $id = $this->payerId($request, $type, needsStripe: false);
        $path = DB::table('invoices')->where(['id' => $invoice, 'owner_type' => $type, 'owner_id' => $id])->value('pdf_path');
        abort_unless(is_string($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('private')->download($path, basename($path));
    }

    private function payerId(Request $request, string $type, bool $needsStripe = true): int
    {
        $user = $this->user($request);
        $id = match ($type) {
            'team' => Team::of($user)?->id,
            'company' => Company::administeredBy($user)?->id,
            default => null,
        };
        abort_unless($id !== null && BillingAccounts::canManage($user, $type, $id), 403);
        abort_unless(! $needsStripe || app(\App\Domains\Platform\Payments\Stripe::class)->connected(), 503, 'Payments aren’t set up yet.');

        return $id;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
