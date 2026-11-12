<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Performance\ServiceScore;
use App\Domains\Referrals\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A network owner's dashboard numbers. As with the team dashboard, each KPI is defined once as the query for its
 * records, so the number and its drill-down always agree. Rankings are tier-aware: in restricted (Tier B) networks
 * they use service quality only, never referral counts or values.
 */
final class OwnerKpis
{
    public const KPIS = [
        'seats' => ['Seats filled', 'count', 'seats'],
        'referrals' => ['Referrals sent in the network', 'count', 'referrals'],
        'accepted' => ['Accepted', 'count', 'referrals'],
        'won' => ['Won', 'count', 'referrals'],
        'conversion' => ['Conversion', 'percent', 'referrals'],
        'breaches' => ['Breaches', 'count', 'breaches'],
        'fees_overdue' => ['Fees overdue', 'money', 'fees'],
    ];

    public const BREACHES = ['missed_first_contact', 'missed_update', 'close_disputed'];

    public static function query(string $key, Group $g, Period $p): Builder
    {
        $in = fn (string $col) => fn (Builder $q) => $q->whereBetween($col, [$p->from, $p->to]);
        $refs = fn () => DB::table('referrals as r')->select('r.*')->where('r.group_id', $g->id);

        return match ($key) {
            'seats' => DB::table('seats as s')->select('s.*')->where('s.group_id', $g->id)->whereNotNull('s.primary_team_id'),
            'referrals' => $refs()->where($in('r.created_at')),
            'accepted' => $refs()->where($in('r.created_at'))->whereNotNull('r.accepted_at'),
            'won' => $refs()->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->where($in('r.close_confirmed_at')),
            'conversion' => $refs()->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->where($in('r.close_confirmed_at')))
                ->orWhere(fn ($w) => $w->where('r.stage', 'lost')->where($in('r.closed_at')))),
            'breaches' => DB::table('referral_events as e')->join('referrals as r', 'r.id', '=', 'e.referral_id')->select('e.*', 'r.ulid', 'r.client_name')
                ->where('r.group_id', $g->id)->whereIn('e.event', self::BREACHES)->where($in('e.created_at')),
            'fees_overdue' => DB::table('referral_fees as f')->join('referrals as r', 'r.id', '=', 'f.referral_id')->select('f.*', 'r.ulid', 'r.client_name')
                ->where('r.group_id', $g->id)->where('f.status', 'due')->where('f.due_on', '<', CarbonImmutable::now()->toDateString()),
            default => throw new InvalidArgumentException("Unknown KPI {$key}"),
        };
    }

    public static function value(string $key, Group $g, Period $p): ?int
    {
        $q = self::query($key, $g, $p);

        return match ($key) {
            'fees_overdue' => (int) $q->sum('f.amount_cents'),
            'conversion' => ($n = $q->count()) === 0 ? null : (int) round(100 * self::query($key, $g, $p)->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->count() / $n),
            default => $q->count(),
        };
    }

    /** @return list<array{key: string, label: string, kind: string, value: ?int, previous: ?int, change: ?int, small: bool}> */
    public static function cards(Group $g, Period $p): array
    {
        $restricted = $g->industry->tier !== Tier::Open;
        $out = [];
        foreach (self::KPIS as $key => [$label, $kind]) {
            if ($restricted && $key === 'fees_overdue') {
                continue; // no fees in restricted industries
            }
            $v = self::value($key, $g, $p);
            $before = in_array($key, ['seats', 'fees_overdue'], true) ? null : self::value($key, $g, $p->previous());
            $count = $key === 'conversion' ? self::query($key, $g, $p)->count() : (int) ($v ?? 0);
            $out[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'value' => $v, 'previous' => $before,
                'change' => $v !== null && $before !== null && $before !== 0 ? (int) round(100 * ($v - $before) / abs($before)) : null,
                'small' => $kind !== 'money' && $key !== 'seats' && ($count < 5 || ($before !== null && $kind === 'count' && $before < 5))];
        }

        return $out;
    }

    /**
     * Top teams. Tier A/C: by referrals sent and accepted, and by wins. Tier B: by service score only.
     *
     * @return array{referrers: list<array{team: string, value: int}>, closers: list<array{team: string, value: int}>, basis: string}
     */
    public static function leaders(Group $g, Period $p): array
    {
        $teams = self::teams($g);
        if ($g->industry->tier !== Tier::Open) {
            $scored = $teams->map(fn (Team $t) => ['team' => $t->name, 'value' => (int) (ServiceScore::for($t)['score'] ?? -1)])->filter(fn ($r) => $r['value'] >= 0)->sortByDesc('value')->take(5)->values()->all();

            return ['referrers' => array_values($scored), 'closers' => [], 'basis' => 'service'];
        }
        $sent = self::query('accepted', $g, $p)->select([])->groupBy('r.sender_team_id')->selectRaw('r.sender_team_id as team_id, count(*) as n')->pluck('n', 'team_id')->all();
        $won = self::query('won', $g, $p)->select([])->groupBy('r.receiver_team_id')->selectRaw('r.receiver_team_id as team_id, count(*) as n')->pluck('n', 'team_id')->all();
        $name = $teams->pluck('name', 'id')->all();
        $top = function (array $counts) use ($name): array {
            arsort($counts);
            $out = [];
            foreach (array_slice($counts, 0, 5, true) as $id => $n) {
                if (isset($name[$id])) {
                    $out[] = ['team' => (string) $name[$id], 'value' => (int) $n];
                }
            }

            return $out;
        };

        return ['referrers' => $top($sent), 'closers' => $top($won), 'basis' => 'activity'];
    }

    /**
     * Teams needing a check-in: 2 or more breaches in the period, or a service score below the network's challenge
     * threshold. Shown to owners only, with the reason.
     *
     * @return list<array{teamId: int, team: string, reasons: list<string>, checkedIn: ?string}>
     */
    public static function lowPerformers(Group $g, Period $p): array
    {
        $breaches = self::query('breaches', $g, $p)->get()->groupBy('team_id');
        $threshold = (int) $g->rule('challenge_threshold');
        $out = [];
        foreach (self::teams($g) as $t) {
            $reasons = [];
            $mine = $breaches->get($t->id, collect());
            foreach (['missed_first_contact' => 'missed first contact', 'missed_update' => 'missed update', 'close_disputed' => 'disputed close'] as $e => $words) {
                $n = $mine->where('event', $e)->count();
                if ($n > 0) {
                    $reasons[] = $n.' '.$words.($n === 1 ? '' : 's');
                }
            }
            $score = ServiceScore::for($t)['score'];
            if ($score !== null && $score < $threshold) {
                $reasons[] = "service score {$score} (below {$threshold})";
            }
            if ($mine->count() >= 2 || ($score !== null && $score < $threshold)) {
                $last = DB::table('owner_checkins')->where(['group_id' => $g->id, 'team_id' => $t->id])->max('created_at');
                $out[] = ['teamId' => $t->id, 'team' => $t->name, 'reasons' => $reasons, 'checkedIn' => $last !== null ? substr((string) $last, 0, 10) : null];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> drill-down rows, short client names only */
    public static function rows(string $key, Group $g, Period $p, int $limit = 500): array
    {
        $kind = self::KPIS[$key][2];
        $order = ['seats' => 's.id', 'referrals' => 'r.id', 'breaches' => 'e.id', 'fees' => 'f.id'][$kind];
        $rows = self::query($key, $g, $p)->orderByDesc($order)->limit($limit)->get();
        $teams = DB::table('teams')->pluck('name', 'id');

        return array_values($rows->map(fn ($r) => match ($kind) {
            'seats' => ['id' => (string) $r->id, 'client' => '', 'with' => (string) ($teams[$r->primary_team_id] ?? ''), 'status' => (string) $r->territory_name, 'date' => substr((string) $r->updated_at, 0, 10), 'link' => '/t/'.DB::table('teams')->where('id', $r->primary_team_id)->value('slug')],
            'referrals' => ['id' => (string) $r->ulid, 'client' => Referral::shorten((string) $r->client_name), 'with' => ($teams[$r->sender_team_id] ?? '').' → '.($teams[$r->receiver_team_id] ?? ''), 'status' => (string) ($r->stage ?? $r->status), 'date' => substr((string) $r->created_at, 0, 10), 'link' => '/app/group/'.$g->slug.'/pipeline'],
            'breaches' => ['id' => (string) $r->id, 'client' => Referral::shorten((string) $r->client_name), 'with' => (string) ($teams[$r->team_id] ?? ''), 'status' => str_replace('_', ' ', (string) $r->event), 'date' => substr((string) $r->created_at, 0, 10), 'link' => '/app/group/'.$g->slug.'/pipeline'],
            default => ['id' => (string) $r->id, 'client' => Referral::shorten((string) $r->client_name), 'with' => ($teams[$r->payer_team_id] ?? '').' → '.($teams[$r->payee_team_id] ?? ''), 'amount' => (int) $r->amount_cents, 'status' => 'due '.$r->due_on, 'date' => substr((string) $r->due_on, 0, 10), 'link' => '/app/group/'.$g->slug.'/disputes'],
        })->all());
    }

    /** @return \Illuminate\Support\Collection<int, Team> */
    private static function teams(Group $g): \Illuminate\Support\Collection
    {
        return Team::query()->whereIn('id', DB::table('seats')->where('group_id', $g->id)->whereNotNull('primary_team_id')->select('primary_team_id'))->get();
    }
}
