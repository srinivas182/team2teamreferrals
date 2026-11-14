<?php

declare(strict_types=1);

namespace App\Domains\Network\Directory;

use App\Domains\Seo\Events\PublicUrlsChanged;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The public addresses a seat, team or group appears on, so a change refreshes them on the CDN (and tells search
 * engines) within seconds instead of waiting for the cache to expire.
 */
final class DirectoryUrls
{
    /** The main city a seat's page is about: the city itself, or the largest enabled city in its county, metro or state. */
    public static function mainCity(string $type, int $territoryId): ?stdClass
    {
        $q = DB::table('cities')->where('cities.is_enabled', true)->orderByDesc('cities.population');
        $city = match ($type) {
            'city' => DB::table('cities')->where('id', $territoryId)->first(['id', 'slug', 'state_id', 'county_id']),
            'county' => $q->where('county_id', $territoryId)->first(['cities.id', 'cities.slug', 'cities.state_id', 'cities.county_id']),
            'metro' => $q->join('county_metro', 'county_metro.county_id', '=', 'cities.county_id')->where('county_metro.metro_id', $territoryId)->first(['cities.id', 'cities.slug', 'cities.state_id', 'cities.county_id']),
            'state' => $q->where('state_id', $territoryId)->first(['cities.id', 'cities.slug', 'cities.state_id', 'cities.county_id']),
            default => null,
        };

        return $city instanceof stdClass ? $city : null;
    }

    /** @return list<string> */
    public static function forSeat(int $seatId): array
    {
        $s = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->join('industries', 'industries.id', '=', 'groups.industry_id')
            ->where('seats.id', $seatId)->first(['seats.territory_type', 'seats.territory_id', 'seats.state_code', 'seats.primary_team_id', 'groups.slug as group', 'industries.slug as industry']);
        if ($s === null) {
            return [];
        }
        $paths = ['/groups', '/groups/'.$s->group];
        if ($s->state_code) {
            $state = '/industries/'.$s->industry.'/'.strtolower((string) $s->state_code);
            $paths[] = $state;
            $city = self::mainCity((string) $s->territory_type, (int) $s->territory_id);
            if ($city !== null) {
                $paths[] = $state.'/'.$city->slug;
            }
        }
        if ($s->primary_team_id && ($slug = DB::table('teams')->where('id', $s->primary_team_id)->value('slug'))) {
            $paths[] = '/t/'.$slug;
        }

        return $paths;
    }

    public static function seatChanged(int $seatId): void
    {
        self::dispatch(self::forSeat($seatId));
    }

    public static function teamChanged(int $teamId): void
    {
        $paths = [];
        foreach (DB::table('seats')->where('primary_team_id', $teamId)->pluck('id') as $id) {
            $paths = [...$paths, ...self::forSeat((int) $id)];
        }
        self::dispatch($paths);
    }

    public static function groupChanged(string $slug): void
    {
        self::dispatch(['/groups', '/groups/'.$slug]);
    }

    /** @param list<string> $paths */
    private static function dispatch(array $paths): void
    {
        $paths = array_values(array_unique($paths));
        if ($paths !== []) {
            event(new PublicUrlsChanged($paths));
        }
    }
}
