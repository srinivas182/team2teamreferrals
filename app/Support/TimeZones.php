<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A US city's time zone from its state and longitude: each state's main zone, plus the states split between
 * zones (El Paso, the Florida panhandle, East/West Tennessee and Kentucky, and others). Arizona keeps no daylight
 * saving. A best estimate for defaults; members can always choose their own.
 */
final class TimeZones
{
    public const BY_STATE = [
        'AL' => 'America/Chicago', 'AK' => 'America/Anchorage', 'AZ' => 'America/Phoenix', 'AR' => 'America/Chicago', 'CA' => 'America/Los_Angeles', 'CO' => 'America/Denver',
        'CT' => 'America/New_York', 'DE' => 'America/New_York', 'DC' => 'America/New_York', 'FL' => 'America/New_York', 'GA' => 'America/New_York', 'HI' => 'Pacific/Honolulu',
        'ID' => 'America/Boise', 'IL' => 'America/Chicago', 'IN' => 'America/Indiana/Indianapolis', 'IA' => 'America/Chicago', 'KS' => 'America/Chicago', 'KY' => 'America/New_York',
        'LA' => 'America/Chicago', 'ME' => 'America/New_York', 'MD' => 'America/New_York', 'MA' => 'America/New_York', 'MI' => 'America/Detroit', 'MN' => 'America/Chicago',
        'MS' => 'America/Chicago', 'MO' => 'America/Chicago', 'MT' => 'America/Denver', 'NE' => 'America/Chicago', 'NV' => 'America/Los_Angeles', 'NH' => 'America/New_York',
        'NJ' => 'America/New_York', 'NM' => 'America/Denver', 'NY' => 'America/New_York', 'NC' => 'America/New_York', 'ND' => 'America/Chicago', 'OH' => 'America/New_York',
        'OK' => 'America/Chicago', 'OR' => 'America/Los_Angeles', 'PA' => 'America/New_York', 'RI' => 'America/New_York', 'SC' => 'America/New_York', 'SD' => 'America/Chicago',
        'TN' => 'America/Chicago', 'TX' => 'America/Chicago', 'UT' => 'America/Denver', 'VT' => 'America/New_York', 'VA' => 'America/New_York', 'WA' => 'America/Los_Angeles',
        'WV' => 'America/New_York', 'WI' => 'America/Chicago', 'WY' => 'America/Denver', 'PR' => 'America/Puerto_Rico',
    ];

    public static function forPlace(string $state, ?float $lat, ?float $lng): string
    {
        $tz = self::BY_STATE[strtoupper($state)] ?? 'America/New_York';
        if ($lng === null) {
            return $tz;
        }

        return match (strtoupper($state)) {
            'TX' => $lng < -104.9 ? 'America/Denver' : $tz,                          // El Paso and Hudspeth
            'FL' => $lng < -85.0 ? 'America/Chicago' : $tz,                          // the western panhandle
            'TN' => $lng > -85.3 ? 'America/New_York' : $tz,                         // East Tennessee
            'KY' => $lng < -86.0 ? 'America/Chicago' : $tz,                          // western Kentucky
            'IN' => ($lng < -86.8 && $lat !== null && ($lat > 41.0 || $lat < 38.5)) ? 'America/Chicago' : $tz, // NW and SW corners
            'MI' => ($lng < -87.5 && $lat !== null && $lat > 45.5) ? 'America/Menominee' : $tz,
            'ND' => ($lng < -101.0 && $lat !== null && $lat < 47.0) ? 'America/Denver' : $tz,
            'SD' => $lng < -100.5 ? 'America/Denver' : $tz,
            'NE' => $lng < -101.0 ? 'America/Denver' : $tz,
            'KS' => $lng < -101.5 ? 'America/Denver' : $tz,
            'ID' => ($lat !== null && $lat > 45.5) ? 'America/Los_Angeles' : $tz,   // the panhandle
            'OR' => $lng > -117.6 ? 'America/Boise' : $tz,                           // Malheur County
            default => $tz,
        };
    }

    /** @param array<int, int> $userIds members whose time zone follows the city, unless they've chosen one */
    public static function applyCityDefault(array $userIds, int $cityId): void
    {
        $city = \Illuminate\Support\Facades\DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')->where('cities.id', $cityId)->first(['states.code', 'cities.lat', 'cities.lon']);
        if ($city === null) {
            return;
        }
        $tz = self::forPlace((string) $city->code, $city->lat !== null ? (float) $city->lat : null, $city->lon !== null ? (float) $city->lon : null);
        \Illuminate\Support\Facades\DB::table('users')->whereIn('id', $userIds)->where('timezone_chosen', false)->update(['timezone' => $tz]);
    }
}
