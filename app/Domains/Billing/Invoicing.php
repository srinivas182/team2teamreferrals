<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Messaging\Notifier;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Payments\Stripe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use stdClass;

/**
 * Monthly billing, in arrears, so the value guarantee can be honoured: on the 1st, each payer gets one invoice for
 * the month just ended, with one line per seat for the days it was billable (after its own free trial, while not
 * inactive), and a free line for any seat that was offered no referral that month (where the group's guarantee is on).
 * The saved card is charged through Stripe; failures retry on days 1, 3 and 5 and drive the agreed seat lifecycle.
 */
final class Invoicing
{
    public const RETRY_DAYS = [1 => 2, 2 => 2]; // after attempt 1 wait 2 days (day 3), after attempt 2 wait 2 days (day 5)

    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Stripe $stripe, private readonly Seats $seats, private readonly Notifier $notifier) {}

    /** Bill the given month (YYYY-MM) for every payer. Safe to run twice: each payer gets one invoice per month. */
    public function run(string $period): int
    {
        $start = CarbonImmutable::parse($period.'-01')->startOfMonth();
        $end = $start->endOfMonth();
        $byPayer = [];
        foreach (Seat::query()->with(['group.industry', 'primary'])->whereNotNull('primary_team_id')->get() as $seat) {
            $payer = BillingAccounts::payerFor($seat);
            if ($payer === null) {
                continue;
            }
            $key = $payer['type'].':'.$payer['id'];
            $line = $this->line($seat, $start, $end, $payer);
            if ($line !== null) {
                $byPayer[$key][] = $line;
            }
            // Annual prepay: billed on this run, covering the 12 months from the start of the coming month.
            $coming = $end->addDay()->startOfDay();
            if ($seat->billing_cycle === 'annual' && ($seat->annual_paid_until === null || $seat->annual_paid_until->lt($coming))
                && ($seat->trial_ends_at === null || $seat->trial_ends_at->lte($coming->endOfMonth()))) {
                $free = (int) data_get($seat->group->pricing, 'annual_months_free', 2);
                $byPayer[$key][] = ['seat_id' => $seat->id, 'group_id' => $seat->group_id, 'annual' => true, 'days' => 0, 'free_reason' => null, 'amount_cents' => PricingRules::annualCents($seat),
                    'type' => 'seat', 'description' => "{$seat->territory_name} · {$seat->group->name} · annual plan, ".$coming->format('M Y').' to '.$coming->addYear()->subDay()->format('M Y').($free > 0 ? " ({$free} months free)" : '')];
            }
        }
        // Premium membership (per team) and private network plans (per owning company), prorated by day.
        $days = fn ($since) => (int) max($start, CarbonImmutable::parse((string) $since)->startOfDay())->diffInDays($end->startOfDay()) + 1;
        foreach (DB::table('teams')->whereNotNull('premium_since')->where('premium_since', '<=', $end)->get(['id', 'premium_since']) as $t) {
            $d = $days($t->premium_since);
            $byPayer['team:'.$t->id][] = ['type' => 'premium', 'days' => $d, 'free_reason' => null, 'amount_cents' => (int) round(PricingRules::price('premium') * 100 * $d / $start->daysInMonth),
                'description' => 'Premium membership · '.$start->format('M Y').($d < $start->daysInMonth ? " ({$d} days)" : '')];
        }
        foreach (DB::table('groups')->where('is_private', true)->whereNotNull('company_id')->where('created_at', '<=', $end)->get(['company_id', 'name', 'created_at']) as $g) {
            $d = $days($g->created_at);
            $byPayer['company:'.$g->company_id][] = ['type' => 'private_network', 'days' => $d, 'free_reason' => null, 'amount_cents' => (int) round(PricingRules::price('private_network') * 100 * $d / $start->daysInMonth),
                'description' => "Private network: {$g->name} · ".$start->format('M Y').($d < $start->daysInMonth ? " ({$d} days)" : '')];
        }
        // AI credit packs bought since the last invoice (already usable; charged here).
        foreach (DB::table('ai_credit_ledger')->where('kind', 'purchase')->whereNull('invoice_id')->where('created_at', '<=', $end->addDay()->endOfDay())->get() as $p) {
            $byPayer['team:'.$p->team_id][] = ['type' => 'ai_credits', 'ai_purchase_id' => (int) $p->id, 'days' => 0, 'free_reason' => null, 'amount_cents' => (int) $p->price_cents, 'description' => 'AI credits: '.number_format((int) $p->credits).' credits'];
        }
        // Platform fees owed on referral fees received (only while collection is switched on).
        if (PricingRules::platformFeeCollectionOn()) {
            foreach (DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->where('f.platform_fee_status', 'owed')->whereNull('f.platform_invoice_id')->get(['f.*', 'r.client_name']) as $f) {
                $byPayer['team:'.$f->payee_team_id][] = ['type' => 'platform_fee', 'platform_fee_id' => (int) $f->id, 'days' => 0, 'free_reason' => null, 'amount_cents' => (int) $f->platform_fee_cents,
                    'description' => 'Platform fee on the $'.number_format($f->amount_cents / 100, 2)." referral fee for {$f->client_name}"];
            }
        }
        $made = 0;
        foreach ($byPayer as $key => $lines) {
            [$type, $id] = explode(':', $key);
            if (DB::table('invoices')->where(['owner_type' => $type, 'owner_id' => (int) $id, 'period' => $period])->exists()) {
                continue;
            }
            $lines = PricingRules::apply($type, (int) $id, $lines);
            $total = (int) max(0, array_sum(array_column($lines, 'amount_cents')));
            if ($total > 0 && PricingRules::stripeTaxOn() && ($tax = $this->salesTax($type, (int) $id, $total, $period)) > 0) {
                $lines[] = ['days' => 0, 'free_reason' => null, 'amount_cents' => $tax, 'description' => 'Sales tax', 'tax' => true, 'type' => 'tax'];
                $total += $tax;
            }
            $invoiceId = DB::table('invoices')->insertGetId([
                'number' => 'T2T-'.str_replace('-', '', $period).'-'.str_pad((string) (DB::table('invoices')->count() + 1), 6, '0', STR_PAD_LEFT),
                'owner_type' => $type, 'owner_id' => (int) $id, 'period' => $period, 'lines' => json_encode($lines), 'total_cents' => $total,
                'status' => $total === 0 ? 'zero' : 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
            PricingRules::commit($type, (int) $id, $lines);
            DB::table('ai_credit_ledger')->whereIn('id', array_filter(array_column($lines, 'ai_purchase_id')))->update(['invoice_id' => $invoiceId]);
            DB::table('referral_fees')->whereIn('id', array_filter(array_column($lines, 'platform_fee_id')))->update(['platform_invoice_id' => $invoiceId, 'platform_fee_status' => 'invoiced']);
            $this->pdf($invoiceId);
            if ($total > 0) {
                $this->charge($invoiceId);
            }
            $made++;
        }

        return $made;
    }

    /**
     * Sales tax through Stripe Tax (uses the address on the payer's Stripe customer). If Stripe can't calculate it,
     * the invoice goes out without tax and admins are alerted, rather than blocking the payer.
     */
    private function salesTax(string $type, int $id, int $amount, string $period): int
    {
        $account = BillingAccounts::account($type, $id);
        if ($account->stripe_customer_id === null) {
            return 0;
        }
        try {
            $calc = $this->stripe->api('POST', 'tax/calculations', ['currency' => 'usd', 'customer' => $account->stripe_customer_id,
                'line_items[0][amount]' => $amount, 'line_items[0][reference]' => "{$type}-{$id}-{$period}", 'line_items[0][tax_behavior]' => 'exclusive'], "tax-{$type}-{$id}-{$period}");

            return (int) ($calc['tax_amount_exclusive'] ?? 0);
        } catch (RuntimeException $e) {
            report($e);

            return 0;
        }
    }

    /** Daily: warn payers 30 days before their saved card expires (once per card). */
    public function cardExpiryWarnings(): void
    {
        $soon = now()->addDays(30);
        foreach (DB::table('billing_customers')->whereNotNull('payment_method_id')->where('card_exp_year', $soon->year)->where('card_exp_month', $soon->month)->get() as $acc) {
            $key = sprintf('%d-%02d', $acc->card_exp_year, $acc->card_exp_month);
            if ($acc->card_warned_for === $key) {
                continue;
            }
            DB::table('billing_customers')->where('id', $acc->id)->update(['card_warned_for' => $key]);
            foreach (BillingAccounts::contacts((string) $acc->owner_type, (int) $acc->owner_id) as $u) {
                $this->notifier->send($u, 'seat.payment', ['group' => 'Billing', 'headline' => "Your card ending {$acc->card_last4} expires soon", 'body' => 'Add a new card on the Billing page so your seats keep running without interruption.'], url('/app/billing'));
            }
        }
    }

    /** Charge an open or failed invoice now (the billing run, a scheduled retry, or "Pay now"). */
    public function charge(int $invoiceId): string
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if ($inv === null || in_array($inv->status, ['paid', 'zero', 'void'], true)) {
            return (string) $inv?->status;
        }
        $account = BillingAccounts::account((string) $inv->owner_type, (int) $inv->owner_id);
        $attempt = (int) $inv->attempts + 1;
        if ($account->payment_method_id === null || $account->stripe_customer_id === null || $account->needs_new_card) {
            return $this->failed($inv, $attempt, $account->needs_new_card ? 'A new card is needed after repeated failed payments.' : 'No card on file.');
        }
        try {
            $pi = $this->stripe->api('POST', 'payment_intents', [
                'amount' => $inv->total_cents, 'currency' => 'usd', 'customer' => $account->stripe_customer_id, 'payment_method' => $account->payment_method_id,
                'off_session' => 'true', 'confirm' => 'true', 'description' => "Team to Team Referrals {$inv->number}",
                'metadata[invoice_id]' => $inv->id, 'metadata[invoice_number]' => $inv->number,
            ], "invoice-{$inv->id}-attempt-{$attempt}");
        } catch (RuntimeException $e) {
            return $this->failed($inv, $attempt, $e->getMessage());
        }
        DB::table('invoices')->where('id', $inv->id)->update(['stripe_payment_intent' => $pi['id'] ?? null, 'attempts' => $attempt, 'updated_at' => now()]);

        return match ($pi['status'] ?? null) {
            'succeeded' => $this->paid((int) $inv->id),
            'requires_action', 'requires_payment_method' => $this->needsAction($inv, $attempt),
            default => 'processing',
        };
    }

    /** Daily: retry failed invoices that are due (days 3 and 5 after the first attempt). */
    public function retryDue(): void
    {
        foreach (DB::table('invoices')->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)->where('next_retry_at', '<=', now())->pluck('id') as $id) {
            $this->charge((int) $id);
        }
    }

    public function paid(int $invoiceId): string
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if ($inv === null || $inv->status === 'paid') {
            return 'paid';
        }
        DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'paid', 'paid_at' => now(), 'next_retry_at' => null, 'failure' => null, 'updated_at' => now()]);
        $this->pdf($invoiceId);
        $lines = (array) json_decode((string) $inv->lines, true);
        // Annual plans start once paid: 12 months from the start of the month after the billed one.
        $coming = CarbonImmutable::parse($inv->period.'-01')->addMonth()->startOfMonth();
        foreach (array_filter($lines, fn ($l) => ! empty($l['annual'])) as $l) {
            DB::table('seats')->where('id', $l['seat_id'])->update(['annual_paid_until' => $coming->addYear()->subDay()->toDateString()]);
        }
        DB::table('referral_fees')->where('platform_invoice_id', $invoiceId)->update(['platform_fee_status' => 'paid']);
        foreach ($this->seatsOf($inv) as $seat) {
            // Paying reactivates instantly; a seat whose trial has ended becomes a paid, active seat.
            if (in_array($seat->status, ['grace', 'inactive'], true)) {
                $this->seats->paymentSucceeded($seat);
            } elseif ($seat->status === 'trial' && $seat->trial_ends_at?->isPast()) {
                $seat->forceFill(['status' => 'active'])->save();
                // A newly paid seat (counts towards the group owner's rewards).
                DB::table('seat_events')->insert(['seat_id' => $seat->id, 'team_id' => $seat->primary_team_id, 'event' => 'activated', 'meta' => null, 'actor_id' => null, 'created_at' => now()]);
            }
        }

        return 'paid';
    }

    public function failedFromWebhook(int $invoiceId, string $reason): void
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if ($inv !== null && ! in_array($inv->status, ['paid', 'failed'], true)) {
            $this->failed($inv, (int) $inv->attempts, $reason);
        }
    }

    /**
     * @param  array{type: string, id: int}  $payer
     * @return array<string, mixed>|null
     */
    private function line(Seat $seat, CarbonImmutable $start, CarbonImmutable $end, array $payer): ?array
    {
        $offered = DB::table('referrals')->where('seat_id', $seat->id)->whereBetween('created_at', [$start, $end])->exists();
        $guarantee = (bool) data_get($seat->group->pricing, 'value_guarantee', false);
        // Covered by an annual plan: nothing to bill, and a month with no referral offered becomes an account credit.
        if ($seat->annual_paid_until !== null && $seat->annual_paid_until->gte($end->startOfDay())) {
            if (! $offered && $guarantee) {
                PricingRules::addCredit($payer['type'], $payer['id'], (int) round(PricingRules::annualCents($seat) / 12),
                    "Free month credit: no referral was offered to {$seat->territory_name} in ".$start->format('M Y'));
            }

            return null;
        }
        $from = $seat->trial_ends_at !== null && $seat->trial_ends_at->gt($start) ? CarbonImmutable::parse($seat->trial_ends_at)->startOfDay() : $start;
        $to = $seat->inactive_at !== null && $seat->inactive_at->lt($end) ? CarbonImmutable::parse($seat->inactive_at)->subDay()->endOfDay() : $end;
        if ($from->gt($end) || $to->lt($from)) {
            return null; // still in its free trial, or inactive all month
        }
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $monthly = (float) data_get($seat->group->pricing, $seat->founding ? 'founding_price' : 'seat_price', 10);
        $amount = (int) round($monthly * 100 * $days / $start->daysInMonth);
        $label = "{$seat->territory_name} · {$seat->group->name} · ".$start->format('M Y');
        $line = ['type' => 'seat', 'seat_id' => $seat->id, 'group_id' => $seat->group_id, 'description' => $label.($days < $start->daysInMonth ? " ({$days} days)" : ''), 'days' => $days, 'amount_cents' => $amount, 'free_reason' => null];
        // The value guarantee: a month in which no referral was offered to the seat is free.
        if (! $offered && $guarantee) {
            $line['amount_cents'] = 0;
            $line['free_reason'] = 'Free month: no referral was offered to this seat.';
        }

        return $line;
    }

    private function failed(stdClass $inv, int $attempt, string $reason): string
    {
        $first = (int) $inv->attempts === 0 || $inv->status === 'open';
        $retry = isset(self::RETRY_DAYS[$attempt]) ? now()->addDays(self::RETRY_DAYS[$attempt]) : null;
        DB::table('invoices')->where('id', $inv->id)->update(['status' => 'failed', 'attempts' => max($attempt, (int) $inv->attempts), 'failure' => mb_substr($reason, 0, 255), 'next_retry_at' => $retry, 'updated_at' => now()]);
        if ($first) {
            foreach ($this->seatsOf($inv) as $seat) {
                $this->seats->paymentFailed($seat); // grace, then the agreed lifecycle
            }
        }
        // Three different invoices failing in 12 months: a new card is required before reactivation.
        if ($first) {
            $failedInvoices = DB::table('invoices')->where(['owner_type' => $inv->owner_type, 'owner_id' => $inv->owner_id])->whereNotNull('failure')->where('created_at', '>=', now()->subYear())->count();
            if ($failedInvoices >= 3) {
                DB::table('billing_customers')->where(['owner_type' => $inv->owner_type, 'owner_id' => $inv->owner_id])->update(['needs_new_card' => true]);
            }
        }
        foreach (BillingAccounts::contacts((string) $inv->owner_type, (int) $inv->owner_id) as $u) {
            $this->notifier->send($u, 'seat.payment', ['group' => 'Billing', 'headline' => "Payment failed for invoice {$inv->number}", 'body' => "{$reason} ".($retry ? 'We’ll try again on '.$retry->toFormattedDateString().', or pay now from the Billing page.' : 'Please update your card and pay from the Billing page.')], url('/app/billing'));
        }

        return 'failed';
    }

    private function needsAction(stdClass $inv, int $attempt): string
    {
        DB::table('invoices')->where('id', $inv->id)->update(['status' => 'action_required', 'attempts' => $attempt, 'updated_at' => now()]);
        foreach (BillingAccounts::contacts((string) $inv->owner_type, (int) $inv->owner_id) as $u) {
            $this->notifier->send($u, 'seat.payment', ['group' => 'Billing', 'headline' => "Your bank needs you to confirm invoice {$inv->number}", 'body' => 'Open the Billing page and press “Pay now” to confirm the payment with your bank.'], url('/app/billing'));
        }

        return 'action_required';
    }

    /** @return list<Seat> */
    private function seatsOf(stdClass $inv): array
    {
        $ids = array_column((array) json_decode((string) $inv->lines, true), 'seat_id');

        return array_values(Seat::query()->with(['group', 'primary.leader'])->whereIn('id', $ids)->get()->all());
    }

    private function pdf(int $invoiceId): void
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if ($inv === null) {
            return;
        }
        $owner = $inv->owner_type === 'company' ? DB::table('companies')->where('id', $inv->owner_id)->value('name') : DB::table('teams')->where('id', $inv->owner_id)->value('name');
        $path = 'invoices/'.$inv->number.'.pdf';
        Storage::disk('private')->put($path, \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', ['inv' => $inv, 'owner' => (string) $owner, 'lines' => (array) json_decode((string) $inv->lines, true)])->output());
        DB::table('invoices')->where('id', $invoiceId)->update(['pdf_path' => $path]);
    }
}
