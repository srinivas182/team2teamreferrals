<?php

declare(strict_types=1);

namespace App\Domains\Community\Badges;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Credits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The badge engine. Badges come from activity over the last 90 days and are re-checked on the 1st of each month:
 * earned, not permanent. Each badge says exactly how to earn and keep it. Top giver is per network.
 */
final class Badges
{
    public const WINDOW_DAYS = 90;

    public const ALL = [
        'fast_responder' => ['name' => 'Fast Responder', 'how' => 'Accept or decline new referrals in under 2 hours on average (the middle value), across at least 5 referrals in the last 90 days.', 'keep' => 'Keep your typical response time under 2 hours. Awarded as soon as you earn it; checked again on the 1st of each month.'],
        'top_closer' => ['name' => 'Top Closer', 'how' => 'Close at least 3 referrals as Won (confirmed by the sender) in the last 90 days, and win at least 40% of the referrals you accepted.', 'keep' => 'Keep closing: 3 confirmed wins and a 40% win rate over the last 90 days. Checked monthly.'],
        'top_referrer' => ['name' => 'Top Referrer', 'how' => 'Be in the top 10% of your network by referrals sent and accepted in the last 90 days (once at least 3 teams have sent referrals).', 'keep' => 'Keep sending referrals your network accepts. Checked monthly.'],
        'perfect_updates' => ['name' => 'Perfect Updates', 'how' => 'Accept at least 5 referrals in the last 90 days and never miss an update deadline on them.', 'keep' => 'Update the referring team before every deadline. One missed update removes it at the next monthly check.'],
        'top_giver' => ['name' => 'Top giver', 'how' => 'Be in the top 10% of your network by credit balance (referrals given minus received) once at least 3 teams have given.', 'keep' => 'Keep giving at least as much as you receive. Checked monthly.'],
        'network_builder' => ['name' => 'Network builder', 'how' => 'Invite 3 or more pros who get their licence verified and accept a first referral.', 'keep' => 'Kept while those invitees remain members. Checked monthly.'],
    ];

    /**
     * Award what's earned (run daily, so recognition follows the achievement); with $revoke (the 1st of each month)
     * also remove what's no longer earned, kept as history. Earned, not permanent.
     */
    public static function recheck(?CarbonImmutable $asOf = null, bool $revoke = true): void
    {
        $now = $asOf ?? CarbonImmutable::now();
        $since = $now->subDays(self::WINDOW_DAYS);
        $earned = []; // "teamId|badge|groupId"
        $teams = Team::query()->whereIn('id', DB::table('seats')->whereNotNull('primary_team_id')->select('primary_team_id'))->get();
        foreach ($teams as $team) {
            $received = DB::table('referrals')->where('receiver_team_id', $team->id)->where('created_at', '>=', $since);
            $answered = (clone $received)->whereNotNull('accepted_at')->get(['created_at', 'accepted_at']);
            $minutes = $answered->map(fn ($r) => CarbonImmutable::parse((string) $r->created_at)->diffInMinutes(CarbonImmutable::parse((string) $r->accepted_at)))->sort()->values();
            if ($minutes->count() >= 5 && $minutes[intdiv($minutes->count(), 2)] <= 120) {
                $earned[] = "{$team->id}|fast_responder|";
            }
            $accepted = $answered->count();
            $won = (clone $received)->whereIn('close_status', ['confirmed', 'auto_confirmed'])->count();
            if ($won >= 3 && $accepted > 0 && $won / $accepted >= 0.4) {
                $earned[] = "{$team->id}|top_closer|";
            }
            $acceptedIds = (clone $received)->whereNotNull('accepted_at')->pluck('id');
            if ($acceptedIds->count() >= 5 && ! DB::table('referral_events')->whereIn('referral_id', $acceptedIds)->where(['event' => 'missed_update', 'team_id' => $team->id])->exists()) {
                $earned[] = "{$team->id}|perfect_updates|";
            }
            if (DB::table('invite_signups')->where('inviter_team_id', $team->id)->whereIn('status', ['rewarded', 'badge', 'capped'])->count() >= 3) {
                $earned[] = "{$team->id}|network_builder|";
            }
        }
        foreach (Group::query()->get() as $group) {
            foreach (Credits::topGivers($group) as $tid) {
                $earned[] = "{$tid}|top_giver|{$group->id}";
            }
            $senders = DB::table('referrals')->where('group_id', $group->id)->where('created_at', '>=', $since)->whereNotNull('accepted_at')
                ->groupBy('sender_team_id')->selectRaw('sender_team_id, count(*) as n')->orderByDesc('n')->get();
            if ($senders->count() >= 3) {
                foreach ($senders->take(max(1, intdiv($senders->count(), 10))) as $s) {
                    $earned[] = "{$s->sender_team_id}|top_referrer|{$group->id}";
                }
            }
        }
        $current = DB::table('member_badges')->whereNull('revoked_at')->get();
        $held = $current->mapWithKeys(fn ($b) => ["{$b->team_id}|{$b->badge}|".($b->group_id ?? '') => $b->id]);
        foreach (array_unique($earned) as $key) {
            if (! $held->has($key)) {
                [$tid, $badge, $gid] = explode('|', $key);
                DB::table('member_badges')->insert(['team_id' => (int) $tid, 'badge' => $badge, 'group_id' => $gid !== '' ? (int) $gid : null, 'awarded_at' => $now]);
            }
        }
        if (! $revoke) {
            return;
        }
        $keep = array_flip(array_unique($earned));
        foreach ($held as $key => $id) {
            if (! isset($keep[$key])) {
                DB::table('member_badges')->where('id', $id)->update(['revoked_at' => $now]); // earned, not permanent
            }
        }
    }

    /** @return list<array{key: string, name: string, group: ?string}> */
    public static function of(Team $team): array
    {
        return array_values(DB::table('member_badges as b')->leftJoin('groups as g', 'g.id', '=', 'b.group_id')->where('b.team_id', $team->id)->whereNull('b.revoked_at')->orderBy('b.badge')
            ->get(['b.badge', 'g.name'])->map(fn ($b) => ['key' => (string) $b->badge, 'name' => self::ALL[$b->badge]['name'] ?? (string) $b->badge, 'group' => $b->name])->all());
    }
}
