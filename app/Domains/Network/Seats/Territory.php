<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats;

use App\Domains\Network\Locations\Models\City;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A team always picks its city; the seat is that city, its county, its metro area or its state, by the industry's territory unit. */
final class Territory
{
    /** @return array{territory_type: string, territory_id: int, territory_name: string, state_code: string} */
    public static function fromCity(string $unit, City $city): array
    {
        $state = DB::table('states')->where('id', $city->state_id)->first(['id', 'code', 'name', 'is_enabled']);
        // Switched-off places (Admin → Locations) never get seats, whichever way someone tries: apply, invite, reserve.
        if (! $city->is_enabled || ! (bool) ($state->is_enabled ?? false)) {
            throw ValidationException::withMessages(['city_id' => 'We’re not taking seats in this location yet.']);
        }
        $code = (string) ($state->code ?? '');
        $county = $city->county_id ? DB::table('counties')->where('id', $city->county_id)->first(['id', 'name']) : null;

        return match ($unit) {
            'city' => ['territory_type' => 'city', 'territory_id' => $city->id, 'territory_name' => "{$city->name}, {$code}", 'state_code' => $code],
            'county' => $county ? ['territory_type' => 'county', 'territory_id' => (int) $county->id, 'territory_name' => "{$county->name}, {$code}", 'state_code' => $code]
                : throw ValidationException::withMessages(['city' => 'We don’t know this city’s county yet. Please pick a nearby city.']),
            'metro' => self::metro($city->county_id, $code),
            'state' => ['territory_type' => 'state', 'territory_id' => (int) ($state->id ?? 0), 'territory_name' => (string) ($state->name ?? $code), 'state_code' => $code],
            default => throw ValidationException::withMessages(['city' => 'Unknown territory type.']),
        };
    }

    /** @return array{territory_type: string, territory_id: int, territory_name: string, state_code: string} */
    private static function metro(?int $countyId, string $code): array
    {
        $metro = $countyId ? DB::table('metros')->join('county_metro', 'county_metro.metro_id', '=', 'metros.id')->where('county_metro.county_id', $countyId)->first(['metros.id', 'metros.name']) : null;
        if ($metro === null) {
            throw ValidationException::withMessages(['city' => 'This city isn’t in a metro area this network covers. Pick the nearest metro city.']);
        }

        return ['territory_type' => 'metro', 'territory_id' => (int) $metro->id, 'territory_name' => (string) $metro->name, 'state_code' => $code];
    }
}
