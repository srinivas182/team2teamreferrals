<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Trust\TrustScore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A team's dashboard numbers. Each KPI is defined once, as the query for its records: the number is a count or sum of
 * that query, and the drill-down lists the same query, so a number and the records behind it always agree.
 */
final class TeamKpis
{
    /** key => [label, kind, records: referrals|fees|credits, how the number is made] */
    public const KPIS = [
        'received' => ['Referrals received', 'count', 'referrals'],
        'accepted' => ['Accepted', 'count', 'referrals'],
        'response' => ['Typical response time', 'minutes', 'referrals'],
        'sent' => ['Referrals sent', 'count', 'referrals'],
        'sent_accepted' => ['Sent and accepted', 'count', 'referrals'],
        'won' => ['Won', 'count', 'referrals'],
        'lost' => ['Lost', 'count', 'referrals'],
        'conversion' => ['Conversion', 'percent', 'referrals'],
        'fees_received' => ['Fees received', 'money', 'fees'],
        'fees_overdue' => ['Fees you owe, overdue', 'money', 'fees'],
        'credits' => ['Credits earned', 'credits', 'credits'],
    ];

    /** The records behind a KPI, for a team and period. */
    public static function query(string $key, Team $team, Period $p): Builder
    {
        $in = fn (string $col) => fn (Builder $q) => $q->whereBetween($col, [$p->from, $p->to]);
        $refs = fn () => DB::table('referrals as r')->select('r.*');

        return match ($key) {
            'received' => $refs()->where('r.receiver_team_id', $team->id)->where($in('r.created_at')),
            'accepted', 'response' => $refs()->where('r.receiver_team_id', $team->id)->where($in('r.created_at'))->whereNotNull('r.accepted_at'),
            'sent' => $refs()->where('r.sender_team_id', $team->id)->where($in('r.created_at')),
            'sent_accepted' => $refs()->where('r.sender_team_id', $team->id)->where($in('r.created_at'))->whereNotNull('r.accepted_at'),
            'won' => $refs()->where('r.receiver_team_id', $team->id)->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->where($in('r.close_confirmed_at')),
            'lost' => $refs()->where('r.receiver_team_id', $team->id)->where('r.stage', 'lost')->where($in('r.closed_at')),
            'conversion' => $refs()->where('r.receiver_team_id', $team->id)->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->where($in('r.close_confirmed_at')))
                ->orWhere(fn ($w) => $w->where('r.stage', 'lost')->where($in('r.closed_at')))),
            'fees_received' => DB::table('referral_fees as f')->select('f.*')->where('f.payee_team_id', $team->id)->where($in('f.received_at')),
            'fees_overdue' => DB::table('referral_fees as f')->select('f.*')->where('f.payer_team_id', $team->id)->where('f.status', 'due')->where('f.due_on', '<', CarbonImmutable::now()->toDateString()),
            'credits' => DB::table('credit_ledger as c')->select('c.*')->where('c.team_id', $team->id)->where('c.credits', '>', 0)->where($in('c.created_at')),
            default => throw new InvalidArgumentException("Unknown KPI {$key}"),
        };
    }

    /** The number, made from the same query its drill-down lists. */
    public static function value(string $key, Team $team, Period $p): ?int
    {
        $q = self::query($key, $team, $p);

        return match ($key) {
            'fees_received', 'fees_overdue' => (int) $q->sum('f.amount_cents'),
            'credits' => (int) $q->sum('c.credits'),
            'response' => self::median($q->get(['r.created_at', 'r.accepted_at'])->map(fn ($r) => (int) CarbonImmutable::parse((string) $r->created_at)->diffInMinutes(CarbonImmutable::parse((string) $r->accepted_at)))->values()->all()),
            'conversion' => ($n = $q->count()) === 0 ? null : (int) round(100 * self::query($key, $team, $p)->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->count() / $n),
            default => $q->count(),
        };
    }

    /** @return list<array{key: string, label: string, kind: string, value: ?int, previous: ?int, change: ?int, small: bool}> */
    public static function cards(Team $team, Period $p): array
    {
        $prev = $p->previous();
        $out = [];
        foreach (self::KPIS as $key => [$label, $kind]) {
            $v = self::value($key, $team, $p);
            $before = $key === 'fees_overdue' ? null : self::value($key, $team, $prev); // "overdue now" has no earlier period
            $count = $key === 'conversion' || $key === 'response' ? self::query($key, $team, $p)->count() : (is_numeric($v) ? (float) $v : 0);
            $out[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'value' => $v, 'previous' => $before,
                'change' => is_numeric($v) && is_numeric($before) && (float) $before !== 0.0 ? (int) round(100 * ((float) $v - (float) $before) / abs((float) $before)) : null,
                // Big percentages on tiny numbers mislead: flag them.
                'small' => $kind !== 'money' && ($count < 5 || (is_numeric($before) && $kind === 'count' && (float) $before < 5))];
        }
        $trust = TrustScore::stored($team->id);
        $out[] = ['key' => 'trust', 'label' => 'Trust score', 'kind' => 'score', 'value' => $trust['score'] ?? null, 'previous' => null, 'change' => null, 'small' => false];

        return $out;
    }

    /** @param array<int, int> $xs */
    private static function median(array $xs): ?int
    {
        if ($xs === []) {
            return null;
        }
        sort($xs);

        return (int) $xs[intdiv(count($xs), 2)];
    }

    /**
     * Drill-down rows: short client names only, never contact details.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $key, Team $team, Period $p, int $limit = 500): array
    {
        $kind = self::KPIS[$key][2];
        $rows = self::query($key, $team, $p)->orderByDesc($kind === 'referrals' ? 'r.id' : ($kind === 'fees' ? 'f.id' : 'c.id'))->limit($limit)->get();
        $teams = DB::table('teams')->pluck('name', 'id');
        $refs = $kind === 'referrals' ? collect() : DB::table('referrals')->whereIn('id', $rows->pluck('referral_id')->filter())->get(['id', 'ulid', 'client_name'])->keyBy('id');

        return array_values($rows->map(function ($r) use ($kind, $team, $teams, $refs) {
            if ($kind === 'referrals') {
                $other = (int) $r->sender_team_id === $team->id ? $r->receiver_team_id : $r->sender_team_id;

                return ['id' => (string) $r->ulid, 'client' => Referral::shorten((string) $r->client_name), 'with' => (string) ($teams[$other] ?? ''), 'status' => (string) ($r->stage ?? $r->status),
                    'date' => substr((string) $r->created_at, 0, 10), 'link' => '/app/referrals/'.$r->ulid];
            }
            $ref = $refs[$r->referral_id] ?? null;
            if ($kind === 'fees') {
                return ['id' => (string) $r->id, 'client' => $ref ? Referral::shorten((string) $ref->client_name) : '', 'with' => (string) ($teams[(int) $r->payer_team_id === $team->id ? $r->payee_team_id : $r->payer_team_id] ?? ''),
                    'amount' => (int) $r->amount_cents, 'status' => (string) $r->status, 'date' => substr((string) ($r->received_at ?? $r->due_on), 0, 10), 'link' => $ref ? '/app/referrals/'.$ref->ulid : '/app/earnings'];
            }

            return ['id' => (string) $r->id, 'client' => $ref ? Referral::shorten((string) $ref->client_name) : '', 'with' => '', 'credits' => (int) $r->credits, 'status' => str_replace('_', ' ', (string) $r->kind),
                'date' => substr((string) $r->created_at, 0, 10), 'link' => $ref ? '/app/referrals/'.$ref->ulid : '/app/credits'];
        })->all());
    }
}
