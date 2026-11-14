<?php

declare(strict_types=1);

namespace App\Domains\Network\Directory;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The interactive network map (Sprint 42): filled, waitlisted and open places for a group or a whole industry.
 * Public facts only: a filled place names its team only when the team's profile is public (others are left off,
 * as on the group page); open places link to "claim this city". Cached briefly and cleared when a seat changes,
 * so the map catches up within a minute.
 */
final class NetworkMap
{
    /** Seconds a map stays cached (seat changes clear it straight away; this bounds everything else, e.g. waitlists). */
    public const TTL = 30;

    /** Open places shown per group: the largest cities without a filled seat. */
    public const OPEN_PER_GROUP = 60;

    /**
     * @return array{title: string, points: list<array<string, mixed>>, counts: array{held: int, waitlist: int, open: int}, updated: string}
     */
    public static function forGroup(Group $group): array
    {
        return Cache::remember(self::key('group', (int) $group->id), self::TTL, fn () => self::wrap($group->name, self::points($group, false)));
    }

    /**
     * @return array{title: string, points: list<array<string, mixed>>, counts: array{held: int, waitlist: int, open: int}, updated: string}
     */
    public static function forIndustry(Industry $industry): array
    {
        return Cache::remember(self::key('industry', (int) $industry->id), self::TTL, function () use ($industry) {
            $points = [];
            foreach (Group::query()->listed()->where('industry_id', $industry->id)->with('industry')->orderBy('name')->get() as $g) {
                $points = [...$points, ...self::points($g, true)];
            }

            return self::wrap($industry->name, $points);
        });
    }

    /**
     * The team's own networks for its dashboard: each group's public map, plus the team's own places (shown even
     * when its profile is private, since only the team sees this), marked as "your seat".
     *
     * @return list<array{group: string, src: string, map: array<string, mixed>, mine: list<array<string, mixed>>}>
     */
    public static function forTeam(\App\Domains\Network\Seats\Models\Team $team): array
    {
        $seats = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->where('seats.primary_team_id', $team->id)
            ->whereIn('seats.status', Directory::FILLED)->where(['groups.status' => 'live', 'groups.is_private' => false])
            ->get(['seats.group_id', 'seats.territory_type', 'seats.territory_id', 'seats.territory_name', 'seats.state_code']);
        $out = [];
        foreach ($seats->groupBy('group_id')->take(3) as $groupId => $mine) {
            $group = Group::query()->with('industry')->find((int) $groupId);
            if ($group === null) {
                continue;
            }
            $points = [];
            foreach ($mine as $s) {
                $city = DirectoryUrls::mainCity((string) $s->territory_type, (int) $s->territory_id);
                $c = $city ? DB::table('cities')->where('id', $city->id)->first(['slug', 'lat', 'lon']) : null;
                if ($c === null || $c->lat === null || $c->lon === null) {
                    continue;
                }
                $points[] = ['key' => $group->id.':'.$s->territory_type.':'.$s->territory_id, 'name' => (string) $s->territory_name, 'state' => strtoupper((string) $s->state_code),
                    'lat' => round((float) $c->lat, 3), 'lon' => round((float) $c->lon, 3), 'status' => 'held', 'team' => ['name' => $team->name, 'slug' => (string) $team->slug],
                    'url' => '/industries/'.$group->industry->slug.'/'.strtolower((string) $s->state_code).'/'.$c->slug];
            }
            $out[] = ['group' => $group->name, 'src' => '/map/group/'.$group->slug.'.json', 'map' => self::forGroup($group), 'mine' => $points];
        }

        return $out;
    }

    /** Called when a seat changes, so the next visitor sees it. */
    public static function forget(int $groupId): void
    {
        Cache::forget(self::key('group', $groupId));
        $industry = DB::table('groups')->where('id', $groupId)->value('industry_id');
        if ($industry !== null) {
            Cache::forget(self::key('industry', (int) $industry));
        }
    }

    /** @return list<array<string, mixed>> */
    private static function points(Group $group, bool $withGroup): array
    {
        $industry = (string) $group->industry->slug;
        $held = Directory::groupSeats($group);
        $open = Directory::openPlaces($group, self::OPEN_PER_GROUP);
        $cityIds = array_values(array_unique([...array_column($held, 'cityId'), ...array_column($open, 'cityId')]));
        $cities = DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')->whereIn('cities.id', $cityIds)
            ->get(['cities.id', 'cities.slug', 'cities.lat', 'cities.lon', 'states.code'])->keyBy('id');
        $base = $withGroup ? ['group' => $group->name, 'groupSlug' => $group->slug] : [];
        $out = [];
        foreach ($held as $s) {
            $c = $cities[$s['cityId']] ?? null;
            if ($c === null || $c->lat === null || $c->lon === null) {
                continue;
            }
            $out[] = [...$base, 'key' => $group->id.':'.$s['type'].':'.$s['territoryId'], 'name' => $s['territory'], 'state' => strtoupper((string) $c->code),
                'lat' => round((float) $c->lat, 3), 'lon' => round((float) $c->lon, 3), 'status' => 'held',
                'team' => ['name' => (string) $s['team']['name'], 'slug' => (string) $s['team']['slug']],
                'url' => '/industries/'.$industry.'/'.strtolower((string) $c->code).'/'.$c->slug];
        }
        foreach ($open as $o) {
            $c = $cities[$o['cityId']] ?? null;
            if ($c === null || $c->lat === null || $c->lon === null) {
                continue;
            }
            $out[] = [...$base, 'key' => $group->id.':open:'.$o['cityId'], 'name' => $o['territory'], 'state' => $o['state'],
                'lat' => round((float) $c->lat, 3), 'lon' => round((float) $c->lon, 3), 'status' => $o['waitlist'] > 0 ? 'waitlist' : 'open', 'waitlist' => $o['waitlist'],
                'url' => '/industries/'.$industry.'/'.strtolower((string) $c->code).'/'.$c->slug, 'claim' => '/claim/'.$group->slug.'/'.$o['cityId']];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @return array{title: string, points: list<array<string, mixed>>, counts: array{held: int, waitlist: int, open: int}, updated: string}
     */
    private static function wrap(string $title, array $points): array
    {
        $statuses = array_column($points, 'status');
        $count = fn (string $status): int => count(array_keys($statuses, $status, true));
        $counts = ['held' => $count('held'), 'waitlist' => $count('waitlist'), 'open' => $count('open')];

        return ['title' => $title, 'points' => $points, 'counts' => $counts, 'updated' => now()->toIso8601String()];
    }

    private static function key(string $kind, int $id): string
    {
        return "netmap:{$kind}:{$id}";
    }
}
