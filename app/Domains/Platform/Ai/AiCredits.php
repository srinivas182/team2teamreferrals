<?php

declare(strict_types=1);

namespace App\Domains\Platform\Ai;

use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * A team's AI credits. Each month brings a free allowance per seat held (more with premium); unused allowance expires
 * at month end, purchased credits never do. Packs are added to the team's next invoice. A daily cap stops runaway use.
 */
final class AiCredits
{
    public const PACKS = [500 => 1000, 2000 => 3000]; // credits => price in cents

    public static function setting(string $key): int
    {
        $defaults = ['per_seat' => '100', 'per_seat_premium' => '300', 'daily_cap' => '200'];

        return (int) app(Settings::class)->get("ai.credits.{$key}", $defaults[$key]);
    }

    /** Make sure this month's allowance is in, expiring last month's unused allowance first. */
    public static function grantMonthly(Team $team): void
    {
        $period = now()->format('Y-m');
        if (DB::table('ai_credit_ledger')->where(['team_id' => $team->id, 'kind' => 'allowance', 'period' => $period])->exists()) {
            return;
        }
        $prev = DB::table('ai_credit_ledger')->where(['team_id' => $team->id, 'kind' => 'allowance'])->orderByDesc('period')->first();
        if ($prev !== null) {
            $used = -(int) DB::table('ai_credit_ledger')->where(['team_id' => $team->id, 'kind' => 'usage'])->where('created_at', 'like', $prev->period.'%')->sum('credits');
            $left = max(0, (int) $prev->credits - $used);
            if ($left > 0) {
                DB::table('ai_credit_ledger')->insertOrIgnore(['team_id' => $team->id, 'kind' => 'expire', 'credits' => -$left, 'period' => $prev->period, 'created_at' => now()]);
            }
        }
        $seats = DB::table('seats')->where('primary_team_id', $team->id)->count();
        $perSeat = $team->premium_since !== null ? self::setting('per_seat_premium') : self::setting('per_seat');
        DB::table('ai_credit_ledger')->insertOrIgnore(['team_id' => $team->id, 'kind' => 'allowance', 'credits' => max(1, $seats) * $perSeat, 'period' => $period, 'created_at' => now()]);
    }

    public static function balance(Team $team): int
    {
        return (int) DB::table('ai_credit_ledger')->where('team_id', $team->id)->sum('credits');
    }

    public static function usedToday(Team $team): int
    {
        return -(int) DB::table('ai_credit_ledger')->where(['team_id' => $team->id, 'kind' => 'usage'])->where('created_at', '>=', now()->startOfDay())->sum('credits');
    }

    public static function purchase(Team $team, int $credits): void
    {
        abort_unless(isset(self::PACKS[$credits]), 422);
        DB::table('ai_credit_ledger')->insert(['team_id' => $team->id, 'kind' => 'purchase', 'credits' => $credits, 'price_cents' => self::PACKS[$credits], 'created_at' => now()]);
    }

    public static function charge(Team $team, int $credits, int $usageId): void
    {
        DB::table('ai_credit_ledger')->insert(['team_id' => $team->id, 'kind' => 'usage', 'credits' => -$credits, 'usage_id' => $usageId, 'created_at' => now()]);
    }
}
