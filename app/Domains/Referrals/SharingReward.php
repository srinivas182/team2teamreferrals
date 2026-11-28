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
 * marked it "not a real client". A team whose sent referrals in a group keep turning out not real (3 in 90 days)
 * earns no reward in that group until that clears or the group owner lifts the pause; receivers' scores are protected.
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

    /**
     * The "not a real client" marks that count against a team in one group: in the last 90 days, not waiting on the
     * owner's review, and after the owner last lifted a pause (lifting starts the count again). Oldest first.
     *
     * @return list<CarbonImmutable>
     */
    public static function invalidDates(int $teamId, int $groupId): array
    {
        $since = now()->subDays(self::INVALID_WINDOW_DAYS);
        $lifted = DB::table('sharing_reward_pauses')->where(['team_id' => $teamId, 'group_id' => $groupId, 'resolution' => 'lifted'])->max('resolved_at');
        if ($lifted !== null && CarbonImmutable::parse((string) $lifted)->gt($since)) {
            $since = CarbonImmutable::parse((string) $lifted);
        }

        return array_values(DB::table('referrals')->where(['sender_team_id' => $teamId, 'group_id' => $groupId])->whereNotNull('invalid_at')->whereNull('invalid_disputed_at')
            ->where('invalid_at', '>', $since)->orderBy('invalid_at')->pluck('invalid_at')->map(fn ($d) => CarbonImmutable::parse((string) $d))->all());
    }

    public static function invalidSent(int $teamId, int $groupId): int
    {
        return count(self::invalidDates($teamId, $groupId));
    }

    /** Too many not-real clients sent in this group lately: no reward here until it clears or the owner lifts it. */
    public static function paused(int $teamId, int $groupId): bool
    {
        return self::invalidSent($teamId, $groupId) >= self::INVALID_LIMIT;
    }

    /** When a pause ends by itself: the day enough of the marks are older than 90 days. Null when not paused. */
    public static function pausedUntil(int $teamId, int $groupId): ?CarbonImmutable
    {
        $dates = self::invalidDates($teamId, $groupId);
        if (count($dates) < self::INVALID_LIMIT) {
            return null;
        }

        return $dates[count($dates) - self::INVALID_LIMIT]->addDays(self::INVALID_WINDOW_DAYS)->startOfDay()->addDay();
    }

    /** Pauses this team had in the group over the last 12 months (for escalating repeat cases). */
    public static function pausesThisYear(int $teamId, int $groupId): int
    {
        return DB::table('sharing_reward_pauses')->where(['team_id' => $teamId, 'group_id' => $groupId])->where('paused_at', '>=', now()->subYear())->count();
    }

    /** Whether a seat's month is free because its team sent a good referral in the group the month before. */
    public static function earned(int $teamId, int $groupId, CarbonImmutable $monthStart): bool
    {
        return ! self::paused($teamId, $groupId) && self::goodSent($teamId, $groupId, $monthStart->subMonthNoOverflow()->startOfMonth()) > 0;
    }

    /**
     * Teams whose reward is paused in a group right now, for the owner to lift, keep or remove.
     *
     * @return list<array{team: string, teamId: int, count: int, until: string, pauses: int, kept: bool}>
     */
    public static function pausedIn(Group $group): array
    {
        $out = [];
        foreach (DB::table('sharing_reward_pauses as p')->join('teams as t', 't.id', '=', 'p.team_id')->where('p.group_id', $group->id)->whereNull('p.resolution')
            ->orWhere(fn ($q) => $q->where('p.group_id', $group->id)->where('p.resolution', 'kept'))->orderBy('p.paused_at')->get(['p.team_id', 't.name', 'p.resolution']) as $row) {
            $teamId = (int) $row->team_id;
            if (isset($out[$teamId]) || ! self::paused($teamId, $group->id)) {
                continue;
            }
            $out[$teamId] = ['team' => (string) $row->name, 'teamId' => $teamId, 'count' => self::invalidSent($teamId, $group->id),
                'until' => (string) self::pausedUntil($teamId, $group->id)?->toFormattedDateString(), 'pauses' => self::pausesThisYear($teamId, $group->id), 'kept' => $row->resolution === 'kept'];
        }

        return array_values($out);
    }

    /**
     * For the team's dashboard: per group with the reward, how this month is going and whether next month is free.
     *
     * @return list<array{group: string, sent: int, nextFree: bool, thisFree: bool, paused: bool, pausedUntil: ?string, notReal: int}>
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
            $notReal = self::invalidSent($team->id, $g->id);
            $paused = $notReal >= self::INVALID_LIMIT;
            $out[] = ['group' => $g->name, 'sent' => $sent, 'nextFree' => ! $paused && $sent > 0, 'thisFree' => self::earned($team->id, $g->id, $month), 'paused' => $paused,
                'pausedUntil' => $paused ? self::pausedUntil($team->id, $g->id)?->toFormattedDateString() : null, 'notReal' => $notReal];
        }

        return $out;
    }
}
