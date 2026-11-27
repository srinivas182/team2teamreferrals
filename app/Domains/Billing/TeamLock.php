<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\DB;

/**
 * Unpaid teams are locked. After the grace days a seat that wasn't paid for goes inactive: its new referrals go to the
 * backup team. When every seat a team holds is inactive, or a charge (a platform fee) is still unpaid after its grace
 * days, the whole team (leader, assistants, members) is locked out
 * until it pays: they see only the Renew page (what they owe, the referrals they missed) and the clients they had
 * already accepted, so no sender's client is dropped mid-deal. Paying reactivates the seats at once.
 */
final class TeamLock
{
    /** Days a failed charge (a platform fee, say) can stay unpaid before the team is locked, like a seat's grace. */
    public const GRACE_DAYS = 7;

    /** Whether the person's team is locked, in one query (this runs on every portal page). */
    public static function lockedFor(\App\Domains\Identity\Models\User $user): bool
    {
        // A derived table (not IN … LIMIT, which MySQL doesn't allow) picks the person's team, as Team::of does.
        $row = DB::selectOne(
            "select (select count(*) from seats where primary_team_id = x.t) as n,
                    (select count(*) from seats where primary_team_id = x.t and status = 'inactive') as off,
                    (select count(*) from invoices where owner_type = 'team' and owner_id = x.t and status = 'failed' and created_at <= ?) as unpaid
             from (select team_id as t from team_members where user_id = ? order by case role when 'leader' then 0 else 1 end limit 1) x",
            [now()->subDays(self::GRACE_DAYS), $user->id],
        );

        return $row !== null && self::decide((int) $row->n, (int) $row->off, (int) $row->unpaid);
    }

    public static function locked(Team $team): bool
    {
        return self::decide(
            DB::table('seats')->where('primary_team_id', $team->id)->count(),
            DB::table('seats')->where(['primary_team_id' => $team->id, 'status' => 'inactive'])->count(),
            self::unpaidCharges($team),
        );
    }

    /** Charges (a platform fee, say) that failed more than the grace days ago and are still unpaid. */
    public static function unpaidCharges(Team $team): int
    {
        return DB::table('invoices')->where(['owner_type' => 'team', 'owner_id' => $team->id, 'status' => 'failed'])->where('created_at', '<=', now()->subDays(self::GRACE_DAYS))->count();
    }

    /** Locked when every seat the team holds is inactive, or a charge has been unpaid past its grace days. */
    private static function decide(int $seats, int $inactive, int $unpaid): bool
    {
        return $unpaid > 0 || ($seats > 0 && $seats === $inactive);
    }

    /** Record that an inactive seat's holder missed this referral (it went to the backup, or wasn't sent there). */
    public static function recordMissed(Seat $seat, ?Referral $ref, string $client): void
    {
        if ($seat->status !== 'inactive' || $seat->primary_team_id === null) {
            return;
        }
        DB::table('missed_referrals')->insert(['team_id' => $seat->primary_team_id, 'seat_id' => $seat->id, 'referral_id' => $ref?->id, 'client' => mb_substr(Referral::shorten($client), 0, 80), 'created_at' => now()]);
    }
}
