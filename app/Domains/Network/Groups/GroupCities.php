<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use Illuminate\Support\Facades\DB;

/**
 * Which cities can join a group: all of them (the default), or only a list the owner chose, state by state.
 */
final class GroupCities
{
    public static function limited(Group $group): bool
    {
        return (bool) DB::table('groups')->where('id', $group->id)->value('cities_limited');
    }

    public static function allows(Group $group, City $city): bool
    {
        return ! self::limited($group) || DB::table('group_cities')->where(['group_id' => $group->id, 'city_id' => $city->id])->exists();
    }

    /**
     * The listed cities, grouped by state ("TX" => [["id" => 1, "name" => "Austin"], ...]).
     *
     * @return array<string, list<array{id: int, name: string}>>
     */
    public static function byState(Group $group): array
    {
        $out = [];
        foreach (DB::table('group_cities as gc')->join('cities as c', 'c.id', '=', 'gc.city_id')->join('states as s', 's.id', '=', 'c.state_id')
            ->where('gc.group_id', $group->id)->orderBy('s.code')->orderBy('c.name')->get(['c.id', 'c.name', 's.code']) as $r) {
            $out[(string) $r->code][] = ['id' => (int) $r->id, 'name' => (string) $r->name];
        }

        return $out;
    }

    /** A short line for public pages: "Open to teams in Austin, Dallas and 3 more cities". */
    public static function summary(Group $group, int $show = 4): ?string
    {
        if (! self::limited($group)) {
            return null;
        }
        $names = DB::table('group_cities as gc')->join('cities as c', 'c.id', '=', 'gc.city_id')->join('states as s', 's.id', '=', 'c.state_id')
            ->where('gc.group_id', $group->id)->orderByDesc('c.population')->get(['c.name', 's.code'])->map(fn ($r) => $r->name.', '.$r->code);
        if ($names->isEmpty()) {
            return 'Not open to new teams yet.';
        }
        $more = $names->count() - $show;

        return 'Open to teams in '.$names->take($show)->implode('; ').($more > 0 ? " and {$more} more ".($more === 1 ? 'city' : 'cities') : '').'.';
    }

    /**
     * Save the setting. Returns how many held seats are in cities no longer listed (they keep their seats).
     *
     * @param  list<int>  $cityIds
     */
    public static function save(Group $group, bool $limited, array $cityIds): int
    {
        DB::transaction(function () use ($group, $limited, $cityIds) {
            DB::table('groups')->where('id', $group->id)->update(['cities_limited' => $limited, 'updated_at' => now()]);
            DB::table('group_cities')->where('group_id', $group->id)->delete();
            if ($limited) {
                $ids = DB::table('cities')->whereIn('id', array_unique($cityIds))->pluck('id');
                foreach ($ids->chunk(500) as $chunk) {
                    DB::table('group_cities')->insert($chunk->map(fn ($id) => ['group_id' => $group->id, 'city_id' => (int) $id])->values()->all());
                }
            }
        });

        return $limited ? self::heldOutside($group) : 0;
    }

    /** Held seats (city seats) whose city isn't on the list. */
    public static function heldOutside(Group $group): int
    {
        return DB::table('seats')->where('group_id', $group->id)->where('territory_type', 'city')->whereNotNull('primary_team_id')
            ->whereNotIn('territory_id', DB::table('group_cities')->where('group_id', $group->id)->select('city_id'))->count();
    }
}
