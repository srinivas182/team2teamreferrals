<?php

declare(strict_types=1);

namespace App\Domains\Network\Directory;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Territory;
use App\Domains\Referrals\ProfileStats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The public directory: groups, the teams holding their seats, and which seats are open. Only what a member has made
 * public is ever shown: team names, logos, cities and aggregate stats (never clients, contact details or money).
 * A seat is "filled" while its team is on trial, active or in grace; inactive and open seats can be claimed.
 */
final class Directory
{
    public const FILLED = ['trial', 'active', 'grace'];

    /** Metro areas whose city pages may be indexed (largest first); widen as coverage grows. */
    public const INDEX_METROS = 300;

    /** @return Collection<int, Group> live, public groups */
    public static function groups(): Collection
    {
        return Group::query()->listed()->with('industry')->orderBy('name')->get();
    }

    /**
     * The directory listing, filtered by industry, state, city and a search term.
     *
     * @param  array{industry?: ?string, state?: ?string, city?: ?int, q?: ?string}  $f
     * @return list<array<string, mixed>>
     */
    public static function listing(array $f): array
    {
        $groups = self::groups();
        $city = isset($f['city']) && $f['city'] ? City::query()->find((int) $f['city']) : null;
        $state = isset($f['state']) && $f['state'] ? strtoupper((string) $f['state']) : null;
        $q = isset($f['q']) ? mb_strtolower(trim((string) $f['q'])) : '';
        $filled = DB::table('seats')->whereIn('status', self::FILLED)->whereNotNull('primary_team_id')
            ->when($state, fn ($w) => $w->where('state_code', $state))->selectRaw('group_id, count(*) as n')->groupBy('group_id')->pluck('n', 'group_id');
        $out = [];
        foreach ($groups as $g) {
            if (! empty($f['industry']) && $g->industry->slug !== $f['industry']) {
                continue;
            }
            if ($q !== '' && ! str_contains(mb_strtolower($g->name.' '.$g->industry->name.' '.$g->description), $q)) {
                continue;
            }
            $row = ['slug' => $g->slug, 'name' => $g->name, 'industry' => $g->industry->name, 'industrySlug' => $g->industry->slug, 'tier' => $g->industry->tier->value,
                'description' => (string) $g->description, 'seatsFilled' => (int) ($filled[$g->id] ?? 0), 'unit' => $g->industry->territory_unit];
            if ($city !== null) {
                $row['citySeat'] = self::seatAt($g, $city);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The seat covering a city in a group: who holds it, or that it is open (and how many are waiting).
     *
     * @return array{territory: string, status: string, team: ?array<string, mixed>, waitlist: int}|null null when the place takes no seats
     */
    public static function seatAt(Group $group, City $city): ?array
    {
        try {
            $t = Territory::fromCity($group->industry->territory_unit, $city);
        } catch (ValidationException) {
            return null;
        }
        $seat = DB::table('seats')->where(['group_id' => $group->id, 'territory_type' => $t['territory_type'], 'territory_id' => $t['territory_id']])->first();
        $filled = $seat !== null && in_array($seat->status, self::FILLED, true) && $seat->primary_team_id !== null;
        $team = $filled ? (self::teams([(int) $seat->primary_team_id], (int) $group->industry_id)[(int) $seat->primary_team_id] ?? null) : null;

        return [
            'territory' => $t['territory_name'],
            'status' => $team !== null ? 'filled' : ($filled ? 'filled_private' : 'open'),
            'team' => $team !== null ? [...$team, 'since' => substr((string) $seat->created_at, 0, 10)] : null,
            'waitlist' => $seat !== null ? DB::table('seat_waitlist')->where('seat_id', $seat->id)->count() : 0,
        ];
    }

    /**
     * Public facts about teams, batched: name, address, logo, verified license states, rating (once there are
     * enough), confirmed closes and trust score. Teams that keep their profile private are left out. Licenses are
     * those verified for the industry, when one is given.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public static function teams(array $ids, ?int $industryId = null): array
    {
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }
        $teams = DB::table('teams')->whereIn('id', $ids)->where('profile_public', true)->get(['id', 'name', 'slug', 'logo_path', 'founded_year']);
        $ratings = DB::table('referral_ratings')->whereIn('rated_team_id', $ids)->selectRaw('rated_team_id, avg(stars) as avg, count(*) as n')->groupBy('rated_team_id')->get()->keyBy('rated_team_id');
        $closed = DB::table('referrals')->whereIn('receiver_team_id', $ids)->whereIn('close_status', ['confirmed', 'auto_confirmed'])
            ->selectRaw('receiver_team_id, count(*) as n')->groupBy('receiver_team_id')->pluck('n', 'receiver_team_id');
        $trust = DB::table('trust_scores')->whereIn('team_id', $ids)->get(['team_id', 'score', 'status'])->keyBy('team_id');
        $licenses = DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->whereIn('team_id', $ids)->where('team_licenses.status', 'verified')->when($industryId, fn ($w) => $w->where('team_licenses.industry_id', $industryId))
            ->get(['team_id', 'states.code'])->groupBy('team_id');
        $out = [];
        foreach ($teams as $t) {
            $r = $ratings->get($t->id);
            $n = (int) ($r->n ?? 0);
            $tr = $trust->get($t->id);
            $out[(int) $t->id] = [
                'name' => (string) $t->name, 'slug' => (string) $t->slug, 'logo' => $t->logo_path ? Storage::disk('public')->url((string) $t->logo_path) : null,
                'foundedYear' => $t->founded_year !== null ? (int) $t->founded_year : null,
                'licenses' => $licenses->get($t->id, collect())->pluck('code')->unique()->values()->all(),
                'rating' => $r !== null && $n >= ProfileStats::MIN_RATINGS ? round((float) $r->avg, 1) : null, 'ratings' => $n,
                'closed' => (int) ($closed[$t->id] ?? 0),
                'trust' => $tr !== null ? ['score' => $tr->score !== null ? (int) $tr->score : null, 'status' => (string) $tr->status] : null,
            ];
        }

        return $out;
    }

    /**
     * A group's filled seats with their public teams, largest places first.
     *
     * @return list<array{territory: string, state: ?string, territoryId: int, type: string, cityId: int, team: array<string, mixed>}>
     */
    public static function groupSeats(Group $group, ?string $state = null): array
    {
        $seats = DB::table('seats')->where('group_id', $group->id)->whereIn('status', self::FILLED)->whereNotNull('primary_team_id')
            ->when($state, fn ($w) => $w->where('state_code', $state))->orderBy('territory_name')->get();
        $teams = self::teams($seats->pluck('primary_team_id')->map(fn ($id) => (int) $id)->unique()->values()->all(), (int) $group->industry_id);
        $out = [];
        foreach ($seats as $s) {
            if (isset($teams[(int) $s->primary_team_id])) {
                $out[] = ['territory' => (string) $s->territory_name, 'state' => $s->state_code, 'territoryId' => (int) $s->territory_id, 'type' => (string) $s->territory_type,
                    'cityId' => (int) (DirectoryUrls::mainCity((string) $s->territory_type, (int) $s->territory_id)->id ?? 0), 'team' => $teams[(int) $s->primary_team_id]];
            }
        }

        return $out;
    }

    /**
     * Open places a team could claim in a group: the largest enabled cities whose seat is not filled.
     *
     * @return list<array{city: string, cityId: int, state: string, territory: string, waitlist: int}>
     */
    public static function openPlaces(Group $group, int $limit = 12, ?string $state = null): array
    {
        $filled = DB::table('seats')->where('group_id', $group->id)->whereIn('status', self::FILLED)->whereNotNull('primary_team_id')
            ->get(['territory_type', 'territory_id'])->map(fn ($s) => $s->territory_type.':'.$s->territory_id)->flip();
        $cities = City::query()->where('cities.is_enabled', true)->join('states', 'states.id', '=', 'cities.state_id')->where('states.is_enabled', true)
            ->when($state, fn ($w) => $w->where('states.code', $state))->orderByDesc('cities.population')->limit($limit * 6)->select('cities.*')->get();
        $out = [];
        $seen = [];
        foreach ($cities as $c) {
            try {
                $t = Territory::fromCity($group->industry->territory_unit, $c);
            } catch (ValidationException) {
                continue;
            }
            $key = $t['territory_type'].':'.$t['territory_id'];
            if (isset($filled[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $seatId = DB::table('seats')->where(['group_id' => $group->id, 'territory_type' => $t['territory_type'], 'territory_id' => $t['territory_id']])->value('id');
            $out[] = ['city' => (string) $c->name, 'cityId' => (int) $c->id, 'state' => $t['state_code'], 'territory' => $t['territory_name'],
                'waitlist' => $seatId ? DB::table('seat_waitlist')->where('seat_id', $seatId)->count() : 0];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Ids of the largest metro areas by population (cached for a day): city pages inside them can be indexed.
     *
     * @return array<int, bool>
     */
    public static function topMetros(int $n = self::INDEX_METROS): array
    {
        return Cache::remember("directory.top-metros.$n", 86400, fn () => DB::table('county_metro')->join('cities', 'cities.county_id', '=', 'county_metro.county_id')
            ->selectRaw('county_metro.metro_id, sum(cities.population) as pop')->groupBy('county_metro.metro_id')->orderByDesc('pop')->limit($n)
            ->pluck('county_metro.metro_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all());
    }

    public static function inTopMetro(City $city): bool
    {
        if ($city->county_id === null) {
            return false;
        }
        $metro = DB::table('county_metro')->where('county_id', $city->county_id)->value('metro_id');

        return $metro !== null && isset(self::topMetros()[(int) $metro]);
    }

    /** @return Collection<int, Group> live public groups in an industry */
    public static function industryGroups(Industry $industry): Collection
    {
        return Group::query()->listed()->with('industry')->where('industry_id', $industry->id)->orderByDesc('is_launch')->orderBy('name')->get();
    }

    /**
     * The answer paragraph for a city page, written from the data (no marketing claims).
     *
     * @param  array<int, array{group: string, groupSlug: string, seat: array{territory: string, status: string, team: ?array<string, mixed>, waitlist: int}|null}>  $seats
     */
    public static function cityAnswer(Industry $industry, City $city, string $stateCode, array $seats): string
    {
        $t = $industry->terms();
        $site = (string) config('seo.site_name');
        $place = $city->name.', '.$stateCode;
        $held = array_values(array_filter($seats, fn ($s) => $s['seat'] !== null && $s['seat']['team'] !== null));
        if ($held === []) {
            $open = array_filter($seats, fn ($s) => $s['seat'] !== null && $s['seat']['status'] === 'open');

            return $open !== []
                ? "The {$place} seat for {$t['pros']} on {$site} is open: no verified ".$t['pro'].' holds it yet. One team per place can claim it; others join the waitlist.'
                : "There is no {$t['pro']} seat available for {$place} on {$site} right now.";
        }
        $s = $held[0];
        $team = $s['seat']['team'];
        $facts = [];
        if ($team['licenses'] !== []) {
            $facts[] = 'license verified in '.implode(', ', $team['licenses']);
        }
        if ($team['trust'] !== null && $team['trust']['score'] !== null) {
            $facts[] = 'trust score '.$team['trust']['score'];
        }
        if ($team['rating'] !== null) {
            $facts[] = 'rated '.$team['rating'].' out of 5 from '.$team['ratings'].' ratings';
        }
        if ($team['closed'] > 0) {
            $facts[] = $team['closed'].' confirmed '.($team['closed'] === 1 ? 'referral' : 'referrals').' closed';
        }

        return "The verified {$t['pro']} for {$place} on {$site} is {$team['name']}, holding the {$s['group']} seat for {$s['seat']['territory']}"
            .($facts !== [] ? ': '.implode(', ', $facts) : '').'.';
    }
}
