<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\DB;

/**
 * Unpaid teams are locked. After the grace days a seat that wasn't paid for goes inactive: its new referrals go to the
 * backup team. When every seat a team holds is inactive, the whole team (leader, assistants, members) is locked out
 * until it pays: they see only the Renew page (what they owe, the referrals they missed) and the clients they had
 * already accepted, so no sender's client is dropped mid-deal. Paying reactivates the seats at once.
 */
final class TeamLock
{
    /** Whether the person's team is locked, in one query (this runs on every portal page). */
    public static function lockedFor(\App\Domains\Identity\Models\User $user): bool
    {
        $team = DB::table('team_members')->where('user_id', $user->id)->orderByRaw("case role when 'leader' then 0 else 1 end")->limit(1)->select('team_id');
        $s = DB::table('seats')->whereIn('primary_team_id', $team)->selectRaw("count(*) as n, sum(case when status = 'inactive' then 1 else 0 end) as off")->first();

        if ($s === null) {
            return false;
        }

        return (int) $s->n > 0 && (int) $s->n === (int) $s->off;
    }

    public static function locked(Team $team): bool
    {
        $statuses = DB::table('seats')->where('primary_team_id', $team->id)->pluck('status');

        return $statuses->isNotEmpty() && $statuses->every(fn ($s) => $s === 'inactive');
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
