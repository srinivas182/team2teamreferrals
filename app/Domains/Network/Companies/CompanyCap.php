<?php

declare(strict_types=1);

namespace App\Domains\Network\Companies;

use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;

/** One company may hold at most a set share of a state's seats in a group (default 25%), but always at least one. */
final class CompanyCap
{
    /** @return array{held: int, of: int, pct: int, cap: int, over: bool}|null null when the team has no company or the seat is state-wide */
    public static function ifHeld(Seat $seat, Team $team): ?array
    {
        if ($team->company_id === null || $seat->territory_type === 'state' || $seat->state_code === null) {
            return null;
        }
        $stateId = (int) DB::table('states')->where('code', $seat->state_code)->value('id');
        $of = match ($seat->territory_type) {
            'city' => DB::table('cities')->where(['state_id' => $stateId, 'is_enabled' => true])->count(),
            'county' => DB::table('counties')->where('state_id', $stateId)->count(),
            'metro' => DB::table('county_metro')->join('counties', 'counties.id', '=', 'county_metro.county_id')->where('counties.state_id', $stateId)->distinct()->count('county_metro.metro_id'),
            default => 0,
        };
        if ($of === 0) {
            return null;
        }
        $held = DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')
            ->where(['seats.group_id' => $seat->group_id, 'seats.state_code' => $seat->state_code, 'teams.company_id' => $team->company_id])->where('seats.id', '!=', $seat->id)->count() + 1;
        $cap = (int) $seat->group->rule('company_cap_pct');
        $pct = (int) floor(100 * $held / $of);

        // A company can always hold at least one seat per state; the cap only limits taking more (small states have few territories).
        return ['held' => $held, 'of' => $of, 'pct' => $pct, 'cap' => $cap, 'over' => $held > 1 && $pct > $cap];
    }
}
