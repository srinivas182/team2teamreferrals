<?php

declare(strict_types=1);

namespace App\Domains\Community\Badges;

use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** On the 1st: each member's network recap for last month, with the badges they gained or lost. */
final class MonthlyRecap
{
    public function __construct(private readonly Notifier $notifier) {}

    public function send(string $period): void
    {
        $start = CarbonImmutable::parse($period.'-01')->startOfMonth();
        $end = $start->endOfMonth();
        foreach (Group::query()->with('industry')->get() as $group) {
            $teams = Team::query()->with('leader')->whereIn('id', DB::table('seats')->where('group_id', $group->id)->whereNotNull('primary_team_id')->select('primary_team_id'))->get();
            if ($teams->isEmpty()) {
                continue;
            }
            $refs = DB::table('referrals')->where('group_id', $group->id)->whereBetween('created_at', [$start, $end]);
            $sent = (clone $refs)->count();
            $won = (clone $refs)->whereIn('close_status', ['confirmed', 'auto_confirmed'])->count();
            $joined = DB::table('seat_events')->join('seats', 'seats.id', '=', 'seat_events.seat_id')->where('seats.group_id', $group->id)->where('seat_events.event', 'claimed')->whereBetween('seat_events.created_at', [$start, $end])->count();
            $leader = (json_decode((string) DB::table('leaderboard_snapshots')->where(['group_id' => $group->id, 'period' => $period])->value('rows'), true) ?: [])[0]['team'] ?? null;
            // Restricted industries: the recap speaks about service, never referral counts.
            $summary = Leaderboards::serviceOnly($group)
                ? ($joined ? "{$joined} new member".($joined === 1 ? '' : 's').'. ' : '').($leader ? "Best service last month: {$leader}." : '')
                : "{$sent} referral".($sent === 1 ? '' : 's').", {$won} closed".($joined ? ", {$joined} new member".($joined === 1 ? '' : 's') : '').'.'.($leader ? " Top of the board: {$leader}." : '');
            foreach ($teams as $team) {
                $gained = DB::table('member_badges')->where('team_id', $team->id)->whereBetween('awarded_at', [$start, now()])->pluck('badge')->map(fn ($b) => Badges::ALL[$b]['name'] ?? $b)->unique();
                $lost = DB::table('member_badges')->where('team_id', $team->id)->whereBetween('revoked_at', [$start, now()])->pluck('badge')->map(fn ($b) => Badges::ALL[$b]['name'] ?? $b)->unique();
                $mine = ($gained->isNotEmpty() ? ' New badges: '.$gained->implode(', ').'.' : '').($lost->isNotEmpty() ? ' To win back: '.$lost->implode(', ').'.' : '');
                $this->notifier->send($team->leader, 'network.recap', ['headline' => "{$group->name} in ".$start->format('F'), 'body' => trim($summary.$mine)], url('/app/leaderboards?group='.$group->slug));
            }
        }
    }
}
