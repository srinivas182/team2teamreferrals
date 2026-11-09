<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agreed launch pricing: $10 per city per group, founders $10, no multi-city discounts.
     * Only groups still on the original $79 sample price are moved, so any price an admin already set is kept.
     */
    public function up(): void
    {
        foreach (DB::table('groups')->get(['id', 'pricing']) as $g) {
            $p = json_decode((string) $g->pricing, true);
            if (! is_array($p) || ($p['seat_price'] ?? null) !== 79) {
                continue;
            }
            $p = [...$p, 'seat_price' => 10, 'founding_price' => 10, 'discounts' => ['2' => 0, '5' => 0, '20' => 0]];
            DB::table('groups')->where('id', $g->id)->update(['pricing' => json_encode($p), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Pricing is business data; it is not rolled back automatically.
    }
};
