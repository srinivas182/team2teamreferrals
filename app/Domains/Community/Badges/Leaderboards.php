<?php

declare(strict_types=1);

namespace App\Domains\Community\Badges;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Performance\ServiceScore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Network leaderboards, monthly (frozen on the 1st) and all-time. Tier-aware: in restricted (Tier B) industries they
 * rank service quality only (service score, response time, updates on time), never referral counts.
 */
final class Leaderboards
{
    public static function serviceOnly(Group $group): bool
    {
        return $group->industry->tier === Tier::Restricted;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(Group $group, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $teams = Team::query()->whereIn('id', DB::table('seats')->where('group_id', $group->id)->whereNotNull('primary_team_id')->select('primary_team_id'))->get();
        $in = fn ($q) => $q->where('group_id', $group->id)->when($from, fn ($w) => $w->where('created_at', '>=', $from))->where('created_at', '<=', $to);
        $rows = $teams->map(function (Team $t) use ($group, $in, $to) {
            if (self::serviceOnly($group)) {
                $accepted = DB::table('referrals')->where('receiver_team_id', $t->id)->whereNotNull('accepted_at')->where($in)->get(['id', 'created_at', 'accepted_at']);
                $mins = $accepted->map(fn ($r) => CarbonImmutable::parse((string) $r->created_at)->diffInMinutes(CarbonImmutable::parse((string) $r->accepted_at)))->sort()->values();
                $missed = DB::table('referral_events')->whereIn('referral_id', $accepted->pluck('id'))->where(['event' => 'missed_update', 'team_id' => $t->id])->distinct()->count('referral_id');

                return ['team' => $t->name, 'score' => ServiceScore::for($t, $to)['score'], 'responseMinutes' => $mins->isEmpty() ? null : (int) $mins[intdiv($mins->count(), 2)],
                    'updatesOnTime' => $accepted->isEmpty() ? null : (int) round(100 * ($accepted->count() - $missed) / $accepted->count())];
            }

            return ['team' => $t->name,
                'sent' => DB::table('referrals')->where('sender_team_id', $t->id)->whereNotNull('accepted_at')->where($in)->count(),
                'closed' => DB::table('referrals')->where('receiver_team_id', $t->id)->whereIn('close_status', ['confirmed', 'auto_confirmed'])->where($in)->count(),
                'score' => ServiceScore::for($t, $to)['score']];
        });
        $sorted = self::serviceOnly($group)
            ? $rows->sortByDesc(fn ($r) => [$r['score'] ?? -1, $r['updatesOnTime'] ?? -1])
            : $rows->sortByDesc(fn ($r) => [$r['sent'] ?? 0, $r['closed'] ?? 0]);

        return array_values($sorted->take(25)->all());
    }

    /** On the 1st: freeze last month's board for every network. */
    public static function snapshot(string $period): void
    {
        $start = CarbonImmutable::parse($period.'-01')->startOfMonth();
        foreach (Group::query()->with('industry')->get() as $g) {
            DB::table('leaderboard_snapshots')->insertOrIgnore(['group_id' => $g->id, 'period' => $period, 'rows' => json_encode(self::rows($g, $start, $start->endOfMonth())), 'created_at' => now()]);
        }
    }
}
