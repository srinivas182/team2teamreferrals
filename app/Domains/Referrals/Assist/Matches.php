<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Assist;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Training\Training;
use App\Domains\Referrals\Performance\ServiceScore;
use Illuminate\Support\Facades\DB;

/**
 * Best matches when a city has no one to receive a referral (empty seat, or declined with no backup): seat holders in
 * the same network nearby, ranked by service score, then distance. Plain rules, no credits.
 */
final class Matches
{
    public const MILES = 60;

    /** The wider searches a sender can ask for when nothing is close. */
    public const RADII = [60, 100, 150];

    public const LIMIT = 5;

    /** @return list<array{team: string, teamSlug: string, cityId: int, city: string, miles: int, score: ?int}> */
    public static function nearby(Group $group, int $cityId, ?int $excludeTeamId = null, int $radius = self::MILES): array
    {
        $origin = DB::table('cities')->where('id', $cityId)->first(['lat', 'lon']);
        if ($origin === null || $origin->lat === null || $origin->lon === null) {
            return [];
        }
        $rows = DB::table('seats')->join('cities', 'cities.id', '=', 'seats.territory_id')->join('states', 'states.id', '=', 'cities.state_id')
            ->where('seats.group_id', $group->id)->whereNotNull('seats.primary_team_id')->where('seats.territory_id', '!=', $cityId)
            ->when($excludeTeamId, fn ($q, $id) => $q->where('seats.primary_team_id', '!=', $id))
            ->get(['seats.primary_team_id', 'cities.id as city_id', 'cities.name', 'states.code', 'cities.lat', 'cities.lon']);
        $training = app(Training::class);
        $out = [];
        foreach ($rows as $r) {
            if ($r->lat === null || $r->lon === null) {
                continue;
            }
            $miles = self::miles((float) $origin->lat, (float) $origin->lon, (float) $r->lat, (float) $r->lon);
            if ($miles > $radius) {
                continue;
            }
            $team = Team::query()->with('leader')->find((int) $r->primary_team_id);
            if ($team === null || $training->blocks($team->leader, $group)) {
                continue;
            }
            $out[] = ['team' => $team->name, 'teamSlug' => (string) $team->slug, 'cityId' => (int) $r->city_id, 'city' => $r->name.', '.$r->code, 'miles' => (int) round($miles), 'score' => ServiceScore::for($team)['score']];
        }
        // Nearest first (the sender is choosing by distance); the better service score breaks a tie.
        usort($out, fn ($a, $b) => [$a['miles'], $b['score'] ?? -1] <=> [$b['miles'], $a['score'] ?? -1]);

        return array_slice($out, 0, self::LIMIT);
    }

    /** Miles between two cities' centres, or null when either has no coordinates. */
    public static function between(int $a, int $b): ?int
    {
        $c = DB::table('cities')->whereIn('id', [$a, $b])->get(['id', 'lat', 'lon'])->keyBy('id');
        $x = $c->get($a);
        $y = $c->get($b);
        if ($x === null || $y === null || $x->lat === null || $y->lat === null || $x->lon === null || $y->lon === null) {
            return null;
        }

        return (int) round(self::miles((float) $x->lat, (float) $x->lon, (float) $y->lat, (float) $y->lon));
    }

    private static function miles(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
