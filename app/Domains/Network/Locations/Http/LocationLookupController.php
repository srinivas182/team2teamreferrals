<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Http;

use App\Domains\Network\Locations\Models\City;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Type-ahead for every city picker (claim flow, send referral, admin). Enabled places only. */
final class LocationLookupController
{
    public function cities(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['cities' => []]);
        }
        $cities = City::query()->lookup(mb_substr($q, 0, 60))->where('is_enabled', true)
            ->whereHas('state', fn (Builder $s) => $s->where('is_enabled', true))
            ->when($request->filled('state'), fn (Builder $b) => $b->whereHas('state', fn (Builder $s) => $s->where('code', strtoupper((string) $request->query('state')))))
            ->with(['state:id,code,name', 'county:id,name'])->limit(10)->get();

        return response()->json(['cities' => $cities->map(fn (City $c) => [
            'id' => $c->id, 'name' => $c->name, 'state' => $c->state->code, 'county' => $c->county?->name, 'population' => $c->population,
            'label' => $c->name.', '.$c->state->code,
        ])], 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
