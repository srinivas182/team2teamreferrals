<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Platform revenue by type, from paid invoices. Referral fees between teams are never platform revenue (the platform
 * never holds that money); only platform fees collected on them are. Sales tax is collected, not earned, so it is
 * shown but kept out of revenue. New invoice lines carry an explicit type; older ones are classified here once.
 */
final class RevenueTypes
{
    public const LABELS = [
        'seat' => 'Seat subscriptions', 'premium' => 'Premium memberships', 'private_network' => 'Private network plans', 'ai_credits' => 'AI credit packs',
        'platform_fee' => 'Platform fees collected', 'discount' => 'Discounts and coupons', 'account_credit' => 'Account credits used', 'tax' => 'Sales tax (collected, not revenue)', 'other' => 'Other',
    ];

    /** @param array<string, mixed> $line */
    public static function classify(array $line): string
    {
        $d = (string) ($line['description'] ?? '');

        return match (true) {
            isset($line['type']) && isset(self::LABELS[(string) $line['type']]) => (string) $line['type'],
            isset($line['seat_id']) => 'seat',
            isset($line['ai_purchase_id']) => 'ai_credits',
            isset($line['platform_fee_id']) => 'platform_fee',
            ! empty($line['tax']) => 'tax',
            ! empty($line['credit']) => 'account_credit',
            isset($line['redemption_id']), str_starts_with($d, 'Multi-city discount') => 'discount',
            str_starts_with($d, 'Premium membership') => 'premium',
            str_starts_with($d, 'Private network:') => 'private_network',
            default => 'other',
        };
    }

    /**
     * @return array{byType: array<string, int>, gross: int, adjustments: int, refunds: int, net: int, tax: int, invoices: int}
     */
    public static function summary(string $from, string $to): array
    {
        $by = array_fill_keys(array_keys(self::LABELS), 0);
        $invoices = DB::table('invoices')->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->get(['lines']);
        foreach ($invoices as $inv) {
            foreach ((array) json_decode((string) $inv->lines, true) as $line) {
                $by[self::classify((array) $line)] += (int) ($line['amount_cents'] ?? 0);
            }
        }
        $refunds = (int) DB::table('refunds')->whereBetween('created_at', [$from, $to])->sum('amount_cents');
        $gross = $by['seat'] + $by['premium'] + $by['private_network'] + $by['ai_credits'] + $by['platform_fee'] + $by['other'];
        $adjust = $by['discount'] + $by['account_credit'];

        return ['byType' => $by, 'gross' => $gross, 'adjustments' => $adjust, 'refunds' => $refunds, 'net' => $gross + $adjust - $refunds, 'tax' => $by['tax'], 'invoices' => $invoices->count()];
    }
}
