<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Reciprocity credits: what a team gives the network and what it gets back. Credits for a referral sent and
 * accepted, a bonus when it closes as Won, and a debit for each referral accepted, at the group's own rates.
 * Each entry belongs to one referral and is recorded once, so balances always match the referral records.
 */
final class Credits
{
    public static function onAccepted(Referral $ref): void
    {
        self::add($ref->sender_team_id, $ref, 'sent_accepted', (int) $ref->group->rule('credit_sent'));
        if ($ref->receiver_team_id !== null) {
            self::add($ref->receiver_team_id, $ref, 'received', (int) $ref->group->rule('credit_received'));
        }
    }

    public static function onWonConfirmed(Referral $ref): void
    {
        self::add($ref->sender_team_id, $ref, 'closed_won', (int) $ref->group->rule('credit_closed'));
    }

    public static function balance(Team $team, ?int $groupId = null): int
    {
        return (int) DB::table('credit_ledger')->where('team_id', $team->id)->when($groupId, fn ($q) => $q->where('group_id', $groupId))->sum('credits');
    }

    /** @return array{given: int, received: int, balance: int} */
    public static function giveGet(Team $team): array
    {
        $rows = DB::table('credit_ledger')->where('team_id', $team->id)->selectRaw('kind, count(*) as n, sum(credits) as c')->groupBy('kind')->get()->keyBy('kind');

        return ['given' => (int) ($rows['sent_accepted']->n ?? 0), 'received' => (int) ($rows['received']->n ?? 0), 'balance' => (int) $rows->sum('c')];
    }

    /**
     * Teams in a group below its minimum balance, when the owner has switched the rule on (flagged, never blocked).
     *
     * @return array<int, array{team: string, balance: int}>
     */
    public static function flagged(Group $group): array
    {
        if (! (bool) $group->rule('min_balance_enabled')) {
            return [];
        }

        return DB::table('credit_ledger as l')->join('teams as t', 't.id', '=', 'l.team_id')->where('l.group_id', $group->id)
            ->groupBy('l.team_id', 't.name')->havingRaw('sum(l.credits) < ?', [(int) $group->rule('min_balance')])
            ->selectRaw('t.name as team, sum(l.credits) as balance')->orderBy('balance')->get()
            ->map(fn ($r) => ['team' => (string) $r->team, 'balance' => (int) $r->balance])->values()->all();
    }

    private static function add(int $teamId, Referral $ref, string $kind, int $credits): void
    {
        if ($credits === 0) {
            return;
        }
        DB::table('credit_ledger')->insertOrIgnore(['team_id' => $teamId, 'group_id' => $ref->group_id, 'referral_id' => $ref->id, 'kind' => $kind, 'credits' => $credits, 'created_at' => now()]);
    }
}
