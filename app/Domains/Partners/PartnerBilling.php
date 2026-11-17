<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use App\Domains\Billing\Invoicing;
use App\Domains\Platform\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Paying for a partner listing (Sprint 47b). A flat price per city each month (the same for everyone in a category,
 * never per lead), or 12 months paid up front at a discount. Paid in advance, after an optional free trial.
 *
 * Lifecycle: trial → active (paid to a date) → renewal on the date. A failed payment keeps the listing up while the
 * three attempts run (past_due); after the last one it is paused and its places go to the waitlist. Paying later
 * brings it back. Cancelling stops renewal; the listing stays up to the end of the time paid for.
 */
final class PartnerBilling
{
    /** @return array{price: float, annualDiscount: int, trialDays: int, cap: int} */
    public static function settings(): array
    {
        $s = app(Settings::class);

        return ['price' => (float) $s->get('partners.price_monthly', '49'), 'annualDiscount' => (int) $s->get('partners.annual_discount', '10'),
            'trialDays' => (int) $s->get('partners.trial_days', '14'), 'cap' => Partners::cap()];
    }

    public static function cities(Partner $p): int
    {
        return PartnerListing::query()->where(['partner_id' => $p->id, 'status' => 'active'])->count();
    }

    /** What a full month costs this partner now, in cents (listed cities only; waitlisted cities are free). */
    public static function monthlyCents(Partner $p): int
    {
        return (int) round(self::settings()['price'] * 100 * self::cities($p));
    }

    public static function annualCents(Partner $p): int
    {
        return (int) round(self::monthlyCents($p) * 12 * (100 - self::settings()['annualDiscount']) / 100);
    }

    /** On approval: a free trial when one is set, otherwise billed straight away. */
    public static function start(Partner $p): void
    {
        $days = self::settings()['trialDays'];
        if ($p->paid_until !== null && $p->paid_until->endOfDay()->isFuture()) {
            $p->forceFill(['billing_status' => 'active'])->save(); // approved again while already paid up

            return;
        }
        $p->forceFill(['billing_status' => $days > 0 ? 'trial' : 'active', 'trial_ends_at' => $days > 0 ? now()->addDays($days) : null, 'cancel_at' => null])->save();
        if ($days === 0) {
            Partners::allocate($p);
            self::bill($p);
        }
    }

    /** Daily: trial reminders, trials ending, renewals due, and cancellations taking effect. */
    public static function run(): void
    {
        $today = now()->startOfDay();
        // Trial ending in 3 days and no card yet: one reminder.
        foreach (Partner::query()->where(['status' => 'approved', 'billing_status' => 'trial'])->whereNull('trial_reminded_at')->where('trial_ends_at', '<=', now()->addDays(3))->get() as $p) {
            $p->forceFill(['trial_reminded_at' => now()])->save();
            if (! self::hasCard($p)) {
                Partners::mail($p, 'Your free trial ends '.$p->trial_ends_at?->toFormattedDateString(), ["Add a card to keep {$p->business_name} listed after your free trial.",
                    'It’s $'.number_format(self::monthlyCents($p) / 100, 2).' a month for your '.self::cities($p).' '.str('city')->plural(self::cities($p)).', or '.self::settings()['annualDiscount'].'% less paid yearly. Cancel any time.'], 'Add a card', url('/partner-portal'));
            }
        }
        // Cancellations take effect at the end of the time paid for.
        foreach (Partner::query()->whereNotNull('cancel_at')->whereDate('cancel_at', '<=', $today->toDateString())->whereIn('billing_status', ['trial', 'active', 'past_due'])->get() as $p) {
            $p->forceFill(['billing_status' => 'cancelled'])->save();
            Partners::mail($p, 'Your listing has ended', ["The listing for {$p->business_name} has ended, as you asked. Nothing more will be charged.", 'You can start again any time from your partner account.'], 'Partner account', url('/partner-portal'));
            Partners::release($p);
            Partners::changed($p);
        }
        // Trials ending, and paid periods ending: bill the next one.
        $due = Partner::query()->with('category')->where('status', 'approved')->whereNull('cancel_at')
            ->where(fn ($q) => $q->where(fn ($t) => $t->where('billing_status', 'trial')->where('trial_ends_at', '<=', now()))
                ->orWhere(fn ($a) => $a->where('billing_status', 'active')->where(fn ($d) => $d->whereNull('paid_until')->orWhereDate('paid_until', '<', $today->toDateString()))))->get();
        foreach ($due as $p) {
            self::bill($p);
        }
    }

    /**
     * Invoice the next period and charge the saved card: the rest of this month (monthly), or 12 months (annual).
     * Nothing is billed while every city is on a waitlist.
     */
    public static function bill(Partner $p, ?CarbonImmutable $from = null): ?int
    {
        $from ??= CarbonImmutable::today();
        $n = self::cities($p);
        if ($n === 0) {
            return null;
        }
        $annual = $p->billing_cycle === 'annual';
        $until = $annual ? $from->addYear()->subDay() : $from->endOfMonth()->startOfDay();
        $days = (int) $from->diffInDays($until) + 1;
        $amount = $annual ? self::annualCents($p) : (int) round(self::monthlyCents($p) * $days / $from->daysInMonth);
        $cities = $n.' '.str('city')->plural($n);
        $line = ['type' => 'partner_listing', 'partner_id' => $p->id, 'until' => $until->toDateString(), 'cycle' => $p->billing_cycle, 'days' => $days, 'free_reason' => null, 'amount_cents' => $amount,
            'description' => "Partner listing: {$p->business_name}, {$cities} · ".$from->format('M j').' to '.$until->format('M j, Y').($annual ? ' (12 months, '.self::settings()['annualDiscount'].'% off)' : '')];
        if ($p->billing_status === 'trial') {
            $p->forceFill(['billing_status' => 'active'])->save();
        }

        return app(Invoicing::class)->issue('partner', $p->id, $from->format('Y-m'), [$line]);
    }

    /**
     * An invoice was paid: the listing runs to the date paid for, and comes back if payment had paused it.
     *
     * @param  array<mixed>  $lines  invoice lines (decoded from the invoice)
     */
    public static function paid(array $lines): void
    {
        foreach ($lines as $l) {
            if (! is_array($l) || ($l['type'] ?? null) !== 'partner_listing') {
                continue;
            }
            $p = Partner::query()->with('category')->find((int) $l['partner_id']);
            if ($p === null) {
                continue;
            }
            $wasPaused = $p->billing_status === 'paused';
            $p->forceFill(['billing_status' => $p->billing_status === 'cancelled' ? 'cancelled' : 'active', 'paid_until' => (string) $l['until']])->save();
            if ($wasPaused) {
                Partners::allocate($p);
                Partners::mail($p, 'Your listing is back', ["Thanks, your payment went through. {$p->business_name} is listed again."], 'See your listing', url($p->path()));
                Partners::changed($p);
            }
        }
    }

    /** A payment failed. The listing stays up while the card is retried; after the last attempt it is paused. */
    public static function failed(int $partnerId, string $number, string $reason, ?Carbon $retry): void
    {
        $p = Partner::query()->with('category')->find($partnerId);
        if ($p === null || in_array($p->billing_status, ['cancelled', 'paused'], true)) {
            return;
        }
        if ($retry !== null) {
            $p->forceFill(['billing_status' => 'past_due'])->save();
            Partners::mail($p, "Payment failed for invoice {$number}", ["We couldn’t take payment for {$p->business_name}'s listing: {$reason}",
                'We’ll try again on '.$retry->toFormattedDateString().'. To keep your listing up, add or update your card in your partner account.'], 'Update card', url('/partner-portal'));

            return;
        }
        $p->forceFill(['billing_status' => 'paused'])->save();
        Partners::mail($p, 'Your listing is paused', ["We couldn’t take payment for {$p->business_name}'s listing after three tries, so it is no longer shown.", 'Add a card in your partner account and it comes straight back.'],
            'Update card', url('/partner-portal'));
        Partners::release($p);
        Partners::changed($p);
    }

    public static function needsAction(int $partnerId, string $number): void
    {
        $p = Partner::query()->find($partnerId);
        if ($p !== null) {
            Partners::mail($p, "Your bank needs you to confirm invoice {$number}", ['Open your partner account and press “Pay now” to confirm the payment with your bank.'], 'Partner account', url('/partner-portal'));
        }
    }

    /** Stop renewing. The listing stays up to the end of the time paid for (or ends now, during a free trial). */
    public static function cancel(Partner $p): void
    {
        $at = $p->billing_status === 'trial' || $p->paid_until === null ? now()->toDateString() : $p->paid_until->copy()->addDay()->toDateString();
        $p->forceFill(['cancel_at' => $at])->save();
        activity('partners')->performedOn($p)->event('partner-cancelled')->log("{$p->business_name} cancelled their listing from {$at}");
        if ($at === now()->toDateString()) {
            self::run();
        }
    }

    public static function resume(Partner $p): void
    {
        $p->forceFill(['cancel_at' => null])->save();
        activity('partners')->performedOn($p)->event('partner-resumed')->log("{$p->business_name} kept their listing");
    }

    /** Start again after cancelling, or after a pause: charged now for the coming period. */
    public static function restart(Partner $p): void
    {
        abort_unless($p->status === 'approved' && in_array($p->billing_status, ['cancelled', 'paused'], true), 422);
        $open = DB::table('invoices')->where(['owner_type' => 'partner', 'owner_id' => $p->id])->whereIn('status', ['failed', 'open', 'action_required'])->orderByDesc('id')->value('id');
        if ($open !== null) {
            app(Invoicing::class)->charge((int) $open); // paying the failed invoice brings the listing back

            return;
        }
        $p->forceFill(['billing_status' => 'active', 'cancel_at' => null])->save();
        Partners::allocate($p);
        if ($p->paid_until === null || $p->paid_until->endOfDay()->isPast()) {
            self::bill($p); // still paid up from before: nothing to charge yet
        }
        Partners::changed($p);
    }

    /** A card was saved: charge anything owed straight away. */
    public static function cardSaved(Partner $p): void
    {
        foreach (DB::table('invoices')->where(['owner_type' => 'partner', 'owner_id' => $p->id])->whereIn('status', ['failed', 'open'])->pluck('id') as $id) {
            app(Invoicing::class)->charge((int) $id);
        }
    }

    public static function hasCard(Partner $p): bool
    {
        return DB::table('billing_customers')->where(['owner_type' => 'partner', 'owner_id' => $p->id])->whereNotNull('payment_method_id')->exists();
    }
}
