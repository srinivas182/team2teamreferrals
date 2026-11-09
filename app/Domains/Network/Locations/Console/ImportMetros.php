<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Loads Census CBSAs (metro and micro areas) and the counties in each, from the official delineation file (CSV). */
final class ImportMetros extends Command
{
    protected $signature = 't2t:locations:import-metros {file : Census CBSA delineation file, saved as CSV}';

    protected $description = 'Import metropolitan and micropolitan areas (CBSAs)';

    public function handle(): int
    {
        $lines = preg_split('/\r?\n/', trim((string) file_get_contents((string) $this->argument('file')))) ?: [];
        // The Census file has title rows above the header; find the header by its first column.
        while ($lines !== [] && ! str_contains((string) $lines[0], 'CBSA Code')) {
            array_shift($lines);
        }
        if ($lines === []) {
            $this->error('No "CBSA Code" header found. Is this the Census delineation file?');

            return self::FAILURE;
        }
        $head = array_map(fn ($h) => trim((string) $h), str_getcsv((string) array_shift($lines), escape: ''));
        $col = fn (string $name) => array_search($name, $head, true);
        [$code, $title, $kind, $st, $co] = [$col('CBSA Code'), $col('CBSA Title'), $col('Metropolitan/Micropolitan Statistical Area'), $col('FIPS State Code'), $col('FIPS County Code')];
        if (in_array(false, [$code, $title, $kind, $st, $co], true)) {
            $this->error('Missing expected columns.');

            return self::FAILURE;
        }
        $counties = DB::table('counties')->pluck('id', 'fips');
        $metros = 0;
        $links = 0;
        foreach ($lines as $line) {
            $v = str_getcsv($line, escape: '');
            if (! isset($v[$co]) || ! preg_match('/^\d{5}$/', (string) $v[$code])) {
                continue; // footnotes
            }
            DB::table('metros')->upsert([[
                'cbsa_code' => $v[$code], 'name' => $v[$title], 'kind' => str_starts_with((string) $v[$kind], 'Metro') ? 'metro' : 'micro',
                'created_at' => now(), 'updated_at' => now(),
            ]], ['cbsa_code'], ['name', 'kind', 'updated_at']);
            $fips = str_pad((string) $v[$st], 2, '0', STR_PAD_LEFT).str_pad((string) $v[$co], 3, '0', STR_PAD_LEFT);
            if (isset($counties[$fips])) {
                $metroId = DB::table('metros')->where('cbsa_code', $v[$code])->value('id');
                DB::table('county_metro')->insertOrIgnore(['metro_id' => $metroId, 'county_id' => $counties[$fips]]);
                $links++;
            }
        }
        $metros = DB::table('metros')->count();
        $this->info("{$metros} metro and micro areas, {$links} county links.");

        return self::SUCCESS;
    }
}
