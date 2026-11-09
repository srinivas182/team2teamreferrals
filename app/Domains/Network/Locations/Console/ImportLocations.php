<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Console;

use App\Domains\Network\Locations\PlaceName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads US states, counties and cities. Safe to re-run: names, coordinates and population are refreshed,
 * but admin decisions (enabled/disabled, corrected county, aliases) are never overwritten.
 */
final class ImportLocations extends Command
{
    protected $signature = 't2t:locations:import {--path= : Folder with states.csv, counties.csv and cities.csv(.gz)}';

    protected $description = 'Import or refresh US states, counties and cities';

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('path') ?: database_path('data/us')), '/');
        $now = now();

        $states = $this->rows("{$dir}/states.csv");
        DB::table('states')->upsert(array_map(fn ($r) => [
            'country' => 'US', 'code' => $r['code'], 'name' => $r['name'], 'fips' => $r['fips'],
            'is_enabled' => $r['code'] !== 'PR', // launch scope: 50 states and DC
            'created_at' => $now, 'updated_at' => $now,
        ], $states), ['country', 'code'], ['name', 'fips', 'updated_at']);
        $stateIds = DB::table('states')->where('country', 'US')->pluck('id', 'code');

        $counties = array_filter(array_map(fn ($r) => isset($stateIds[$r['state']]) ? [
            'state_id' => $stateIds[$r['state']], 'fips' => $r['fips'], 'name' => $r['name'], 'lat' => $r['lat'], 'lon' => $r['lon'],
            'created_at' => $now, 'updated_at' => $now,
        ] : null, $this->rows("{$dir}/counties.csv")));
        foreach (array_chunk($counties, 1000) as $chunk) {
            DB::table('counties')->upsert($chunk, ['fips'], ['name', 'lat', 'lon', 'state_id', 'updated_at']);
        }
        $countyIds = DB::table('counties')->pluck('id', 'fips');

        $file = is_file("{$dir}/cities.csv.gz") ? "{$dir}/cities.csv.gz" : "{$dir}/cities.csv";
        $seen = [];
        $batch = [];
        $count = 0;
        foreach ($this->rows($file) as $r) {
            $stateId = $stateIds[$r['state']] ?? null;
            $slug = Str::slug($r['name']);
            if ($stateId === null || $slug === '' || isset($seen[$stateId.'|'.$slug])) {
                continue;
            }
            $seen[$stateId.'|'.$slug] = true;
            $batch[] = [
                'state_id' => $stateId, 'county_id' => $countyIds[$r['county_fips']] ?? null,
                'name' => $r['name'], 'slug' => $slug, 'search_name' => PlaceName::normalize($r['name']),
                'lat' => $r['lat'] !== '' ? $r['lat'] : null, 'lon' => $r['lon'] !== '' ? $r['lon'] : null,
                'population' => (int) $r['population'], 'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($batch) === 1000) {
                $count += $this->flushCities($batch);
                $batch = [];
            }
        }
        $count += $this->flushCities($batch);

        $this->info(sprintf('%d states, %d counties, %d cities in place.', count($states), count($counties), $count));

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $batch */
    private function flushCities(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }
        // county_id is set on insert only, so an admin's correction survives re-imports.
        DB::table('cities')->upsert($batch, ['state_id', 'slug'], ['name', 'search_name', 'lat', 'lon', 'population', 'updated_at']);

        return count($batch);
    }

    /** @return list<array<string, string>> */
    private function rows(string $file): array
    {
        $raw = str_ends_with($file, '.gz') ? (string) gzdecode((string) file_get_contents($file)) : (string) file_get_contents($file);
        $lines = preg_split('/\r?\n/', trim($raw)) ?: [];
        $head = array_map(fn ($h) => (string) $h, str_getcsv((string) array_shift($lines), escape: ''));
        $out = [];
        foreach ($lines as $line) {
            $vals = str_getcsv($line, escape: '');
            if (count($vals) === count($head)) {
                $out[] = array_combine($head, array_map('strval', $vals));
            }
        }

        return $out;
    }
}
