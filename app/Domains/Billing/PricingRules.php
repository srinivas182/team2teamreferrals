<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The adjustments on a payer's invoice, in a fixed order so totals are predictable: multi-city discounts per network,
 * then a coupon, then account credits (never below zero). Plus annual prepay lines and platform fees owed.
 */
final class PricingRules
{
    public static function platformFeeCollectionOn(): bool
    {
        // Settings are stored as text: only an explicit '1' switches collection on.
        return app(Settings::class)->get('billing.platform_fee_collection', '0') === '1';
    }

    /** Plan prices from Admin → Billing (defaults until changed). */
    public static function price(string $plan): float
    {
        $default = ['premium' => '19', 'private_network' => '49'][$plan] ?? '0';

        return (float) app(Settings::class)->get("billing.{$plan}_price", $default);
    }

    public static function stripeTaxOn(): bool
    {
        return app(Settings::class)->get('billing.stripe_tax', '0') === '1';
    }

    /** Annual price for a seat: 12 months less the group's free months (default 2). */
    public static function annualCents(Seat $seat): int
    {
        $monthly = (float) data_get($seat->group->pricing, $seat->founding ? 'founding_price' : 'seat_price', 10);

        return (int) round($monthly * 100 * (12 - (int) data_get($seat->group->pricing, 'annual_months_free', 2)));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function apply(string $type, int $id, array $lines): array
    {
        // Multi-city discounts: per network, by how many of its seats this payer holds (highest tier reached).
        $byGroup = [];
        foreach ($lines as $l) {
            if (isset($l['seat_id']) && empty($l['annual']) && (int) $l['amount_cents'] > 0) {
                $byGroup[(int) $l['group_id']][] = $l;
            }
        }
        foreach ($byGroup as $groupId => $groupLines) {
            $pricing = (array) json_decode((string) DB::table('groups')->where('id', $groupId)->value('pricing'), true);
            $held = Seat::query()->where('group_id', $groupId)->whereIn('id', array_column($groupLines, 'seat_id'))->count();
            $pct = 0;
            foreach ((array) ($pricing['discounts'] ?? []) as $min => $percent) {
                if ($held >= (int) $min) {
                    $pct = max($pct, (int) $percent);
                }
            }
            if ($pct > 0) {
                $sum = array_sum(array_column($groupLines, 'amount_cents'));
                $lines[] = ['type' => 'discount', 'description' => "Multi-city discount: {$pct}% for {$held} seats in ".DB::table('groups')->where('id', $groupId)->value('name'), 'amount_cents' => -(int) round($sum * $pct / 100), 'free_reason' => null];
            }
        }
        // A coupon, for its remaining months.
        $r = DB::table('coupon_redemptions')->join('coupons', 'coupons.id', '=', 'coupon_redemptions.coupon_id')
            ->where(['owner_type' => $type, 'owner_id' => $id])->where('months_left', '>', 0)->first(['coupon_redemptions.id', 'coupons.code', 'coupons.percent_off', 'coupons.amount_off_cents']);
        $subtotal = array_sum(array_column($lines, 'amount_cents'));
        if ($r !== null && $subtotal > 0) {
            $off = $r->percent_off ? (int) round($subtotal * $r->percent_off / 100) : min((int) $r->amount_off_cents, $subtotal);
            $lines[] = ['type' => 'discount', 'description' => "Coupon {$r->code}", 'amount_cents' => -$off, 'free_reason' => null, 'redemption_id' => (int) $r->id];
            $subtotal -= $off;
        }
        // Account credits last, never taking the total below zero.
        $available = (int) DB::table('billing_credits')->where(['owner_type' => $type, 'owner_id' => $id])->selectRaw('coalesce(sum(amount_cents - used_cents), 0) as left_cents')->value('left_cents');
        if ($available > 0 && $subtotal > 0) {
            $lines[] = ['type' => 'account_credit', 'description' => 'Account credit', 'amount_cents' => -min($available, $subtotal), 'free_reason' => null, 'credit' => true];
        }

        return $lines;
    }

    /**
     * After an invoice is created: use the coupon month and the credits it applied.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public static function commit(string $type, int $id, array $lines): void
    {
        foreach ($lines as $l) {
            if (isset($l['redemption_id'])) {
                DB::table('coupon_redemptions')->where('id', $l['redemption_id'])->decrement('months_left');
            }
            if (! empty($l['credit'])) {
                $toUse = -(int) $l['amount_cents'];
                foreach (DB::table('billing_credits')->where(['owner_type' => $type, 'owner_id' => $id])->whereColumn('used_cents', '<', 'amount_cents')->orderBy('id')->get() as $c) {
                    $take = min($toUse, (int) $c->amount_cents - (int) $c->used_cents);
                    DB::table('billing_credits')->where('id', $c->id)->increment('used_cents', $take);
                    $toUse -= $take;
                    if ($toUse <= 0) {
                        break;
                    }
                }
            }
        }
    }

    public static function addCredit(string $type, int $id, int $cents, string $reason): void
    {
        // Keyed on the reason, so re-running a month never credits twice.
        if (! DB::table('billing_credits')->where(['owner_type' => $type, 'owner_id' => $id, 'reason' => $reason])->exists()) {
            DB::table('billing_credits')->insert(['owner_type' => $type, 'owner_id' => $id, 'amount_cents' => $cents, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
