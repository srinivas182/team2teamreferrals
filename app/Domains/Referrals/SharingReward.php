<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The sharing reward. In a group that has it, a team that sends at least one good referral in a month gets its seat
 * free the next month: one free month at most, however many it sends, so the habit is to send every month.
 *
 * Good: the other team accepted it and logged first contact with the client (so the client is real), and nobody
 * marked it "not a real client". A team whose sent referrals keep turning out not real (3 in 90 days) earns no
 * reward until that clears; the receivers' scores are protected from those referrals.
 */
final class SharingReward
{
    /** The exact wording teams see before they join (kept in step with SHARING_REWARD_TEXT in resources/js/lib/utils.ts). */
    public const TEXT = 'Sharing reward: send at least one referral in a month that the other team accepts and contacts, and your seat is free the next month. One free month at most each month, however many you send.';

    public const INVALID_LIMIT = 3;

    public const INVALID_WINDOW_DAYS = 90;

    public static function on(Group $group): bool
    {
        return (bool) ($group->pricing['sharing_reward'] ?? false) && $group->industry->tier->allowsSharingReward();
    }

    /** Good referrals a team sent in a group during a month. */
    public static function goodSent(int $teamId, int $groupId, CarbonImmutable $monthStart): int
    {
        return DB::table('referrals')->where(['sender_team_id' => $teamId, 'group_id' => $groupId])
            ->whereBetween('created_at', [$monthStart, $monthStart->endOfMonth()])
            ->whereNotNull('accepted_at')->whereNotNull('first_contact_at')->whereNull('invalid_at')->count();
    }

    /** Referrals a team sent that receivers marked not real, recently. */
    public static function invalidSent(int $teamId): int
    {
        return DB::table('referrals')->where('sender_team_id', $teamId)->whereNotNull('invalid_at')->whereNull('invalid_disputed_at')
            ->where('invalid_at', '>=', now()->subDays(self::INVALID_WINDOW_DAYS))->count();
    }

    /** Too many not-real clients sent lately: no reward until it clears. */
    public static function paused(int $teamId): bool
    {
        return self::invalidSent($teamId) >= self::INVALID_LIMIT;
    }

    /** Whether a seat's month is free because its team sent a good referral in the group the month before. */
    public static function earned(int $teamId, int $groupId, CarbonImmutable $monthStart): bool
    {
        return ! self::paused($teamId) && self::goodSent($teamId, $groupId, $monthStart->subMonthNoOverflow()->startOfMonth()) > 0;
    }

    /**
     * For the team's dashboard: per group with the reward, how this month is going and whether next month is free.
     *
     * @return list<array{group: string, sent: int, nextFree: bool, thisFree: bool, paused: bool}>
     */
    public static function progress(Team $team): array
    {
        $month = CarbonImmutable::now()->startOfMonth();
        $out = [];
        foreach (Group::query()->with('industry')->whereIn('id', DB::table('seats')->where('primary_team_id', $team->id)->pluck('group_id'))->get() as $g) {
            if (! self::on($g)) {
                continue;
            }
            $sent = self::goodSent($team->id, $g->id, $month);
            $paused = self::paused($team->id);
            $out[] = ['group' => $g->name, 'sent' => $sent, 'nextFree' => ! $paused && $sent > 0, 'thisFree' => self::earned($team->id, $g->id, $month), 'paused' => $paused];
        }

        return $out;
    }
}
