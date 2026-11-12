<?php

declare(strict_types=1);

namespace App\Domains\Platform\Reports;

use App\Domains\Billing\RevenueTypes;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Referrals\Dashboard\Period;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Trust\TrustScore;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The report builder's datasets. Each run is scoped: an owner's report only ever reads its own network, and revenue
 * needs "view revenue", checked against the person the report is for, every time it runs. Client names are short;
 * contact details are never included.
 */
final class Datasets
{
    public const SETS = [
        'referrals' => ['label' => 'Referrals', 'columns' => ['date' => 'Date', 'network' => 'Network', 'industry' => 'Industry', 'state' => 'State', 'city' => 'City', 'sender' => 'Sender', 'receiver' => 'Receiver', 'stage' => 'Stage', 'client' => 'Client'],
            'groupBy' => ['network' => 'Network', 'industry' => 'Industry', 'state' => 'State', 'month' => 'Month', 'stage' => 'Stage'], 'platformOnly' => false],
        'revenue' => ['label' => 'Revenue', 'columns' => ['type' => 'Type', 'amount' => 'Amount ($)'], 'groupBy' => [], 'platformOnly' => true],
        'seats' => ['label' => 'Seats', 'columns' => ['network' => 'Network', 'industry' => 'Industry', 'state' => 'State', 'city' => 'City', 'holder' => 'Holder', 'status' => 'Status'],
            'groupBy' => ['network' => 'Network', 'industry' => 'Industry', 'state' => 'State', 'status' => 'Status'], 'platformOnly' => false],
        'teams' => ['label' => 'Teams', 'columns' => ['team' => 'Team', 'seats' => 'Seats', 'trust' => 'Trust score', 'joined' => 'Joined'], 'groupBy' => [], 'platformOnly' => false],
    ];

    public const SCREEN_ROWS = 500;

    /** Can this person receive (or run) this report? */
    public static function allowed(User $user, string $dataset, ?Group $group): bool
    {
        if ($group !== null) {
            return ! self::SETS[$dataset]['platformOnly'] && ($group->isOwnedBy($user) || ($user->isStaff() && $user->can('referrals.view')));
        }
        if (! $user->isStaff() || ! $user->can('referrals.view')) {
            return false;
        }

        return $dataset !== 'revenue' || $user->can('revenue.view');
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $filters
     * @return array{columns: list<string>, rows: list<list<string|int>>, total: int}
     */
    public static function run(string $dataset, ?Group $group, Period $p, array $columns, array $filters, ?string $groupBy, ?int $limit = null): array
    {
        $def = self::SETS[$dataset];
        $columns = array_values(array_intersect($columns === [] ? array_keys($def['columns']) : $columns, array_keys($def['columns'])));
        $records = match ($dataset) {
            'referrals' => self::referrals($group, $p, $filters),
            'revenue' => self::revenue($p),
            'seats' => self::seats($group, $filters),
            default => self::teams($group),
        };
        if ($groupBy !== null && isset($def['groupBy'][$groupBy])) {
            $counts = [];
            foreach ($records as $r) {
                $k = $groupBy === 'month' ? substr((string) $r['date'], 0, 7) : (string) ($r[$groupBy] ?? '');
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
            arsort($counts);
            $rows = array_map(fn ($k, $n) => [$k, $n], array_keys($counts), $counts);

            return ['columns' => [$def['groupBy'][$groupBy], 'Count'], 'rows' => $limit ? array_slice($rows, 0, $limit) : $rows, 'total' => count($rows)];
        }
        $rows = array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? '', $columns), $records);

        return ['columns' => array_map(fn ($c) => $def['columns'][$c], $columns), 'rows' => $limit ? array_slice($rows, 0, $limit) : $rows, 'total' => count($rows)];
    }

    /**
     * Run a saved report row.
     *
     * @return array{columns: list<string>, rows: list<list<string|int>>, total: int}
     */
    public static function runSaved(stdClass $report, ?Group $group, Period $p, ?int $limit = null): array
    {
        $columns = array_values(array_map('strval', array_filter((array) json_decode((string) $report->columns, true), 'is_string')));
        $filters = array_map('strval', array_filter((array) json_decode((string) $report->filters, true), 'is_string'));

        return self::run((string) $report->dataset, $group, $p, $columns, $filters, $report->group_by !== null ? (string) $report->group_by : null, $limit);
    }

    /** @param array{columns: list<string>, rows: list<list<string|int>>} $result */
    public static function csv(array $result): string
    {
        $h = fopen('php://temp', 'r+');
        if ($h === false) {
            return '';
        }
        fputcsv($h, $result['columns']);
        foreach ($result['rows'] as $r) {
            fputcsv($h, $r);
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }

    /**
     * @param  array<string, string>  $f
     * @return list<array<string, string|int>>
     */
    private static function referrals(?Group $group, Period $p, array $f): array
    {
        $teams = DB::table('teams')->pluck('name', 'id');

        return array_values(DB::table('referrals as r')->join('groups as g', 'g.id', '=', 'r.group_id')->join('industries as i', 'i.id', '=', 'g.industry_id')
            ->join('seats as s', 's.id', '=', 'r.seat_id')->join('cities as c', 'c.id', '=', 's.territory_id')->join('states as st', 'st.id', '=', 'c.state_id')
            ->when($group, fn ($q, $g) => $q->where('r.group_id', $g->id))
            ->when($f['industry'] ?? null, fn ($q, $v) => $q->where('i.slug', $v))->when($f['state'] ?? null, fn ($q, $v) => $q->where('st.code', strtoupper($v)))
            ->when($f['stage'] ?? null, fn ($q, $v) => in_array($v, ['won', 'lost'], true) ? $q->where('r.stage', $v) : $q->where('r.status', $v))
            ->whereBetween('r.created_at', [$p->from, $p->to])->orderByDesc('r.id')->limit(100000)
            ->get(['r.created_at', 'g.name as network', 'i.name as industry', 'st.code as state', 'c.name as city', 'r.sender_team_id', 'r.receiver_team_id', 'r.stage', 'r.status', 'r.client_name'])
            ->map(fn ($r) => ['date' => substr((string) $r->created_at, 0, 10), 'network' => (string) $r->network, 'industry' => (string) $r->industry, 'state' => (string) $r->state, 'city' => (string) $r->city,
                'sender' => (string) ($teams[$r->sender_team_id] ?? ''), 'receiver' => (string) ($teams[$r->receiver_team_id] ?? ''), 'stage' => (string) ($r->stage ?? $r->status), 'client' => Referral::shorten((string) $r->client_name)])->all());
    }

    /** @return list<array<string, string|int>> */
    private static function revenue(Period $p): array
    {
        $s = RevenueTypes::summary($p->from->toDateTimeString(), $p->to->toDateTimeString());
        $rows = [];
        foreach (RevenueTypes::LABELS as $type => $label) {
            if ($type !== 'tax') {
                $rows[] = ['type' => $label, 'amount' => number_format($s['byType'][$type] / 100, 2, '.', '')];
            }
        }
        $rows[] = ['type' => 'Refunds', 'amount' => number_format(-$s['refunds'] / 100, 2, '.', '')];
        $rows[] = ['type' => 'Net revenue', 'amount' => number_format($s['net'] / 100, 2, '.', '')];

        return $rows;
    }

    /**
     * @param  array<string, string>  $f
     * @return list<array<string, string|int>>
     */
    private static function seats(?Group $group, array $f): array
    {
        $teams = DB::table('teams')->pluck('name', 'id');

        return array_values(DB::table('seats as s')->join('groups as g', 'g.id', '=', 's.group_id')->join('industries as i', 'i.id', '=', 'g.industry_id')
            ->join('cities as c', 'c.id', '=', 's.territory_id')->join('states as st', 'st.id', '=', 'c.state_id')
            ->when($group, fn ($q, $g) => $q->where('s.group_id', $g->id))->when($f['state'] ?? null, fn ($q, $v) => $q->where('st.code', strtoupper($v)))
            ->when($f['industry'] ?? null, fn ($q, $v) => $q->where('i.slug', $v))
            ->get(['g.name as network', 'i.name as industry', 'st.code as state', 'c.name as city', 's.primary_team_id'])
            ->map(fn ($s) => ['network' => (string) $s->network, 'industry' => (string) $s->industry, 'state' => (string) $s->state, 'city' => (string) $s->city,
                'holder' => (string) ($teams[$s->primary_team_id] ?? ''), 'status' => $s->primary_team_id ? 'filled' : 'open'])->all());
    }

    /** @return list<array<string, string|int>> */
    private static function teams(?Group $group): array
    {
        $seats = DB::table('seats')->whereNotNull('primary_team_id')->when($group, fn ($q, $g) => $q->where('group_id', $g->id))->groupBy('primary_team_id')->selectRaw('primary_team_id, count(*) as n')->pluck('n', 'primary_team_id');

        return array_values(DB::table('teams')->whereIn('id', $seats->keys())->orderBy('name')->get(['id', 'name', 'created_at'])
            ->map(fn ($t) => ['team' => (string) $t->name, 'seats' => (int) $seats[$t->id], 'trust' => (string) (TrustScore::stored((int) $t->id)['score'] ?? 'New'), 'joined' => substr((string) $t->created_at, 0, 10)])->all());
    }
}
