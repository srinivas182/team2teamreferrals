<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Seat map points and network demand figures. */
final class SeatMap
{
    /** @return list<array{id: int, name: string, state: ?string, lat: float, lon: float, state_of: string, holder: ?string, backup: ?string, waitlist: int}> */
    public static function points(Group $group): array
    {
        $seats = DB::table('seats')->leftJoin('teams as p', 'p.id', '=', 'seats.primary_team_id')->leftJoin('teams as b', 'b.id', '=', 'seats.backup_team_id')
            ->where('seats.group_id', $group->id)->get(['seats.id', 'seats.territory_type', 'seats.territory_id', 'seats.territory_name', 'seats.state_code', 'seats.status', 'p.name as holder', 'b.name as backup']);
        $waits = DB::table('seat_waitlist')->whereIn('seat_id', $seats->pluck('id'))->selectRaw('seat_id, count(*) n')->groupBy('seat_id')->pluck('n', 'seat_id');
        $out = [];
        foreach ($seats as $s) {
            $c = self::coords((string) $s->territory_type, (int) $s->territory_id);
            if ($c === null) {
                continue;
            }
            $w = (int) ($waits[$s->id] ?? 0);
            $out[] = ['id' => (int) $s->id, 'name' => (string) $s->territory_name, 'state' => $s->state_code, 'lat' => $c[0], 'lon' => $c[1],
                'state_of' => $s->holder ? 'held' : ($s->backup ? 'backup' : ($w > 0 ? 'waitlist' : 'open')), 'holder' => $s->holder, 'backup' => $s->backup, 'waitlist' => $w];
        }

        return $out;
    }

    /** @return array{0: float, 1: float}|null */
    private static function coords(string $type, int $id): ?array
    {
        $row = match ($type) {
            'city' => DB::table('cities')->where('id', $id)->first(['lat', 'lon']),
            'county' => DB::table('counties')->where('id', $id)->first(['lat', 'lon']),
            'metro' => DB::table('counties')->join('county_metro', 'county_metro.county_id', '=', 'counties.id')->where('county_metro.metro_id', $id)->selectRaw('avg(lat) as lat, avg(lon) as lon')->first(),
            'state' => DB::table('counties')->where('state_id', $id)->selectRaw('avg(lat) as lat, avg(lon) as lon')->first(),
            default => null,
        };

        return $row && $row->lat !== null && $row->lon !== null ? [(float) $row->lat, (float) $row->lon] : null;
    }

    /** Waitlisted teams across an industry's public groups. */
    public static function waitlisted(Industry $industry): int
    {
        return (int) DB::table('seat_waitlist')->join('seats', 'seats.id', '=', 'seat_waitlist.seat_id')->join('groups', 'groups.id', '=', 'seats.group_id')
            ->where('groups.industry_id', $industry->id)->where('groups.is_private', false)->count();
    }

    /** Share of the 100 largest metro areas where the industry already has a seat holder; null for state-wide industries. */
    public static function metroFill(Industry $industry): ?int
    {
        if ($industry->territory_unit === 'state') {
            return null;
        }
        $top = self::topMetros();
        if ($top->isEmpty()) {
            return 0;
        }
        $held = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->where('groups.industry_id', $industry->id)->where('groups.is_private', false)->whereNotNull('seats.primary_team_id')->get(['seats.territory_type', 'seats.territory_id']);
        $metros = collect();
        foreach ($held as $s) {
            $metros = $metros->merge(match ((string) $s->territory_type) {
                'metro' => [(int) $s->territory_id],
                'county' => DB::table('county_metro')->where('county_id', $s->territory_id)->pluck('metro_id')->all(),
                'city' => DB::table('cities')->join('county_metro', 'county_metro.county_id', '=', 'cities.county_id')->where('cities.id', $s->territory_id)->pluck('county_metro.metro_id')->all(),
                default => [],
            });
        }

        return (int) round(100 * $top->intersect($metros->unique())->count() / $top->count());
    }

    /** @return Collection<int, int> ids of the 100 most populous metro areas (cached daily) */
    private static function topMetros(): Collection
    {
        /** @var list<int> $ids */
        $ids = Cache::remember('seats:top-metros', 86400, fn () => DB::table('cities')->join('county_metro', 'county_metro.county_id', '=', 'cities.county_id')
            ->selectRaw('county_metro.metro_id as id, sum(cities.population) as pop')->groupBy('county_metro.metro_id')->orderByDesc('pop')->limit(100)->pluck('id')->map(fn ($v) => (int) $v)->all());

        return collect($ids);
    }
}
