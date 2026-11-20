<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats;

use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Network\Locations\PlaceName;
use App\Domains\Network\Seats\Models\Seat;
use Illuminate\Support\Facades\DB;

/**
 * Inviting teams from a list you already have (Admin → Groups → a group → Seat map → Invite teams → Upload a list):
 * a CSV with email, city and state. Each row is matched to a city and its seat in the group, so the preview shows
 * what will happen before anything is sent: open seat, taken (they'd be backup or waitlisted), already invited,
 * or a problem to fix.
 */
final class InviteList
{
    public const MAX_ROWS = 500;

    private const COLUMNS = [
        'email' => ['email', 'e-mail', 'email address', 'mail'],
        'city' => ['city', 'town', 'city name'],
        'state' => ['state', 'st', 'state code', 'province'],
        'name' => ['name', 'full name', 'contact', 'contact name', 'team', 'team name', 'company', 'first name'],
    ];

    /**
     * @return array{rows: list<array{line: int, email: string, name: string, city: string, state: string, city_id: ?int, place: ?string, seat: ?string, problem: ?string}>, error: ?string}
     */
    public function preview(Group $group, string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', ltrim($csv, "\xEF\xBB\xBF")) ?: [], fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return ['rows' => [], 'error' => 'The file is empty.'];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv($lines[0]));
        $col = [];
        foreach (self::COLUMNS as $key => $names) {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    $col[$key] = $i;
                    break;
                }
            }
        }
        if (! isset($col['email'], $col['city'], $col['state'])) {
            return ['rows' => [], 'error' => 'The first row must name the columns, including email, city and state (e.g. email,city,state).'];
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            return ['rows' => [], 'error' => 'Up to '.self::MAX_ROWS.' rows at a time; split the list into smaller files.'];
        }

        $states = State::query()->where('country', 'US')->get(['id', 'code', 'name']);
        $stateId = fn (string $s) => $states->first(fn ($st) => strcasecmp($st->code, trim($s)) === 0 || strcasecmp($st->name, trim($s)) === 0)?->id;
        $seen = [];
        $rows = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            $f = str_getcsv($line);
            $email = strtolower(trim((string) ($f[$col['email']] ?? '')));
            $city = trim((string) ($f[$col['city']] ?? ''));
            $state = trim((string) ($f[$col['state']] ?? ''));
            $row = ['line' => $n + 2, 'email' => $email, 'name' => isset($col['name']) ? trim((string) ($f[$col['name']] ?? '')) : '', 'city' => $city, 'state' => $state,
                'city_id' => null, 'place' => null, 'seat' => null, 'problem' => null];
            $sid = $stateId($state);
            $match = $sid === null ? null : City::query()->where('state_id', $sid)->where('search_name', PlaceName::normalize($city))->orderByDesc('population')->first();
            $row['problem'] = match (true) {
                ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'Not a valid email address',
                isset($seen[$email]) => 'Listed twice (line '.$seen[$email].')',
                $sid === null => 'Unknown state "'.$state.'"',
                $match === null => 'No city "'.$city.'" found in '.$state,
                default => null,
            };
            $seen[$email] ??= $row['line'];
            try {
                $t = $match !== null ? Territory::fromCity($group->industry->territory_unit, $match) : null;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $t = null;
                $row['problem'] ??= (string) collect($e->errors())->flatten()->first();
            }
            if ($match !== null && $t !== null) {
                $row['city_id'] = $match->id;
                $row['place'] = $t['territory_name'];
                $seat = Seat::query()->where(['group_id' => $group->id, 'territory_type' => $t['territory_type'], 'territory_id' => $t['territory_id']])->first();
                $invited = $seat !== null && DB::table('seat_invites')->where(['seat_id' => $seat->id, 'email' => $email, 'status' => 'pending'])->where('expires_at', '>', now())->exists();
                $row['seat'] = $invited ? 'invited' : ($seat !== null && $seat->primary_team_id !== null ? 'taken' : 'open');
                if ($invited && $row['problem'] === null) {
                    $row['problem'] = 'Already invited to this seat';
                }
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'error' => null];
    }
}
