<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use App\Domains\Referrals\Referral;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The whole network, for super admins. As on the other dashboards, each number is defined once as the query for its
 * records, so a number and its drill-down agree.
 */
final class NetworkKpis
{
    public const KPIS = [
        'teams' => ['Teams with a seat', 'count', 'teams'],
        'groups' => ['Networks with members', 'count', 'groups'],
        'seats_filled' => ['Seats filled', 'count', 'seats'],
        'seats_open' => ['Seats open', 'count', 'seats'],
        'referrals' => ['Referrals', 'count', 'referrals'],
        'accepted' => ['Accepted', 'count', 'referrals'],
        'won' => ['Won', 'count', 'referrals'],
        'conversion' => ['Conversion', 'percent', 'referrals'],
        'breaches' => ['Breaches', 'count', 'breaches'],
    ];

    public static function query(string $key, Period $p): Builder
    {
        $in = fn (string $col) => fn (Builder $q) => $q->whereBetween($col, [$p->from, $p->to]);
        $refs = fn () => DB::table('referrals as r')->select('r.*');

        return match ($key) {
            'teams' => DB::table('teams as t')->select('t.*')->whereIn('t.id', DB::table('seats')->whereNotNull('primary_team_id')->select('primary_team_id')),
            'groups' => DB::table('groups as g')->select('g.*')->whereIn('g.id', DB::table('seats')->whereNotNull('primary_team_id')->select('group_id')),
            'seats_filled' => DB::table('seats as s')->select('s.*')->whereNotNull('s.primary_team_id'),
            'seats_open' => DB::table('seats as s')->select('s.*')->whereNull('s.primary_team_id'),
            'referrals' => $refs()->where($in('r.created_at')),
            'accepted' => $refs()->where($in('r.created_at'))->whereNotNull('r.accepted_at'),
            'won' => $refs()->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->where($in('r.close_confirmed_at')),
            'conversion' => $refs()->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->where($in('r.close_confirmed_at')))
                ->orWhere(fn ($w) => $w->where('r.stage', 'lost')->where($in('r.closed_at')))),
            'breaches' => DB::table('referral_events as e')->join('referrals as r', 'r.id', '=', 'e.referral_id')->select('e.*', 'r.ulid', 'r.client_name', 'r.group_id')
                ->whereIn('e.event', OwnerKpis::BREACHES)->where($in('e.created_at')),
            default => throw new InvalidArgumentException("Unknown KPI {$key}"),
        };
    }

    public static function value(string $key, Period $p): ?int
    {
        $q = self::query($key, $p);

        return $key === 'conversion'
            ? (($n = $q->count()) === 0 ? null : (int) round(100 * self::query($key, $p)->whereIn('r.close_status', ['confirmed', 'auto_confirmed'])->count() / $n))
            : $q->count();
    }

    /** @return list<array{key: string, label: string, kind: string, value: ?int, previous: ?int, change: ?int, small: bool}> */
    public static function cards(Period $p): array
    {
        $out = [];
        foreach (self::KPIS as $key => [$label, $kind]) {
            $v = self::value($key, $p);
            $snapshot = in_array($key, ['teams', 'groups', 'seats_filled', 'seats_open'], true); // "now", no earlier period
            $before = $snapshot ? null : self::value($key, $p->previous());
            $out[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'value' => $v, 'previous' => $before,
                'change' => $v !== null && $before !== null && $before !== 0 ? (int) round(100 * ($v - $before) / abs($before)) : null,
                'small' => ! $snapshot && (($key === 'conversion' ? self::query($key, $p)->count() : (int) $v) < 5)];
        }

        return $out;
    }

    /** @return list<array{day: string, referrals: int, breaches: int}> SLA trend: referrals and breaches per day */
    public static function trend(Period $p): array
    {
        $refs = DB::table('referrals')->whereBetween('created_at', [$p->from, $p->to])->selectRaw('date(created_at) as d, count(*) as n')->groupByRaw('date(created_at)')->pluck('n', 'd');
        $br = DB::table('referral_events')->whereIn('event', OwnerKpis::BREACHES)->whereBetween('created_at', [$p->from, $p->to])->selectRaw('date(created_at) as d, count(*) as n')->groupByRaw('date(created_at)')->pluck('n', 'd');
        $out = [];
        for ($d = $p->from; $d->lte($p->to); $d = $d->addDay()) {
            $out[] = ['day' => $d->toDateString(), 'referrals' => (int) ($refs[$d->toDateString()] ?? 0), 'breaches' => (int) ($br[$d->toDateString()] ?? 0)];
        }

        return $out;
    }

    /** @return array{groups: list<array{name: string, slug: string, referrals: int}>, cities: list<array{name: string, referrals: int}>} */
    public static function top(Period $p): array
    {
        $groups = DB::table('referrals as r')->join('groups as g', 'g.id', '=', 'r.group_id')->whereBetween('r.created_at', [$p->from, $p->to])
            ->groupBy('g.id', 'g.name', 'g.slug')->selectRaw('g.name, g.slug, count(*) as n')->orderByDesc('n')->limit(10)->get();
        $cities = DB::table('referrals as r')->join('seats as s', 's.id', '=', 'r.seat_id')->whereBetween('r.created_at', [$p->from, $p->to])
            ->groupBy('s.territory_name')->selectRaw('s.territory_name as name, count(*) as n')->orderByDesc('n')->limit(10)->get();

        return ['groups' => array_values($groups->map(fn ($g) => ['name' => (string) $g->name, 'slug' => (string) $g->slug, 'referrals' => (int) $g->n])->all()),
            'cities' => array_values($cities->map(fn ($c) => ['name' => (string) $c->name, 'referrals' => (int) $c->n])->all())];
    }

    /** @return list<array<string, mixed>> drill-down rows; short client names only */
    public static function rows(string $key, Period $p, int $limit = 500): array
    {
        $kind = self::KPIS[$key][2];
        $order = ['teams' => 't.id', 'groups' => 'g.id', 'seats' => 's.id', 'referrals' => 'r.id', 'breaches' => 'e.id'][$kind];
        $rows = self::query($key, $p)->orderByDesc($order)->limit($limit)->get();
        $teams = DB::table('teams')->pluck('name', 'id');
        $groups = DB::table('groups')->pluck('name', 'id');

        return array_values($rows->map(fn ($r) => match ($kind) {
            'teams' => ['id' => (string) $r->id, 'client' => '', 'with' => (string) $r->name, 'status' => '', 'date' => substr((string) $r->created_at, 0, 10), 'link' => '/t/'.$r->slug],
            'groups' => ['id' => (string) $r->id, 'client' => '', 'with' => (string) $r->name, 'status' => '', 'date' => substr((string) $r->created_at, 0, 10), 'link' => '/admin/groups/'.$r->slug],
            'seats' => ['id' => (string) $r->id, 'client' => '', 'with' => (string) ($groups[$r->group_id] ?? ''), 'status' => (string) $r->territory_name.($r->primary_team_id ? ' · '.($teams[$r->primary_team_id] ?? '') : ' · open'), 'date' => substr((string) $r->updated_at, 0, 10), 'link' => '/admin/groups/'.DB::table('groups')->where('id', $r->group_id)->value('slug')],
            'referrals' => ['id' => (string) $r->ulid, 'client' => Referral::shorten((string) $r->client_name), 'with' => ($teams[$r->sender_team_id] ?? '').' → '.($teams[$r->receiver_team_id] ?? ''), 'status' => (string) ($r->stage ?? $r->status), 'date' => substr((string) $r->created_at, 0, 10), 'link' => '/admin/referrals?q='.$r->ulid],
            default => ['id' => (string) $r->id, 'client' => Referral::shorten((string) $r->client_name), 'with' => (string) ($teams[$r->team_id] ?? ''), 'status' => str_replace('_', ' ', (string) $r->event), 'date' => substr((string) $r->created_at, 0, 10), 'link' => '/admin/referrals?q='.$r->ulid],
        })->all());
    }
}
