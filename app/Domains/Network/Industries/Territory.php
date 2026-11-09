<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries;

use Illuminate\Support\Facades\DB;

/** How many seats each territory choice creates across enabled places, before an admin commits to it. */
final class Territory
{
    /** @return array<string, int> */
    public static function preview(): array
    {
        $enabledStates = DB::table('states')->where('is_enabled', true)->pluck('id');

        return [
            'city' => DB::table('cities')->where('is_enabled', true)->whereIn('state_id', $enabledStates)->count(),
            'county' => DB::table('counties')->whereIn('state_id', $enabledStates)->count(),
            'metro' => DB::table('metros')->count(),
            'state' => $enabledStates->count(),
        ];
    }
}
