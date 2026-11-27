<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every group's free trial becomes 1 month (new groups start at 1 too). Seats already in a trial keep their end date. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('groups')->get(['id', 'pricing']) as $g) {
            $p = (array) json_decode((string) $g->pricing, true);
            $p['trial_months'] = 1;
            DB::table('groups')->where('id', $g->id)->update(['pricing' => json_encode($p)]);
        }
    }

    public function down(): void
    {
        // Trial lengths before this aren't kept; set them again in Admin → Groups → Pricing if needed.
    }
};
