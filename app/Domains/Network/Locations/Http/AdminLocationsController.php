<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations\Http;

use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Locations\Models\CityAlias;
use App\Domains\Network\Locations\Models\County;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Network\Locations\PlaceName;
use App\Domains\Seo\SeoData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Locations: which places are open for seats, fixes to source data, aliases and duplicates. */
final class AdminLocationsController
{
    public function index(Request $request): Response
    {
        $state = $request->string('state')->toString();
        $q = trim($request->string('q')->toString());
        $cities = City::query()->with(['state:id,code', 'county:id,name', 'aliases:id,city_id,alias'])
            ->when($state !== '', fn (Builder $b) => $b->whereHas('state', fn (Builder $s) => $s->where('code', $state)))
            ->when($q !== '', fn (Builder $b) => $b->lookup($q), fn (Builder $b) => $b->orderByDesc('population'))
            ->when($request->query('show') === 'disabled', fn (Builder $b) => $b->where('is_enabled', false))
            ->paginate(25)->withQueryString();

        return Inertia::render('admin/locations/Index', [
            'states' => State::query()->withCount(['cities', 'cities as enabled_cities_count' => fn (Builder $b) => $b->where('is_enabled', true)])->orderBy('name')
                ->get()->map(fn (State $s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'enabled' => $s->is_enabled, 'cities' => (int) $s->getAttribute('cities_count'), 'enabledCities' => (int) $s->getAttribute('enabled_cities_count')]),
            'cities' => collect($cities->items())->map(fn (City $c) => [
                'id' => $c->id, 'name' => $c->name, 'state' => $c->state->code, 'county' => $c->county?->name, 'countyId' => $c->county_id,
                'population' => $c->population, 'enabled' => $c->is_enabled, 'aliases' => $c->aliases->map(fn (CityAlias $a) => ['id' => $a->id, 'alias' => $a->alias])->values(),
            ]),
            'pagination' => ['current' => $cities->currentPage(), 'last' => $cities->lastPage(), 'total' => $cities->total()],
            'filters' => ['state' => $state, 'q' => $q, 'show' => $request->query('show')],
            'totals' => ['cities' => City::query()->count(), 'counties' => County::query()->count(), 'metros' => DB::table('metros')->count()],
            'status' => session('status'),
            'seo' => SeoData::private('Locations')->toArray(),
        ]);
    }

    public function toggleState(Request $request, State $state): RedirectResponse
    {
        $state->forceFill(['is_enabled' => ! $state->is_enabled])->save();
        $this->audit($request, ($state->is_enabled ? 'Enabled ' : 'Disabled ').$state->name);

        return back()->with('status', 'location-saved');
    }

    public function updateCity(Request $request, City $city): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'county_id' => ['sometimes', 'nullable', 'integer'],
            'population' => ['sometimes', 'integer', 'min:0', 'max:20000000'],
        ]);
        if (array_key_exists('county_id', $data) && $data['county_id'] !== null && ! County::query()->whereKey($data['county_id'])->where('state_id', $city->state_id)->exists()) {
            throw ValidationException::withMessages(['county_id' => 'Choose a county in the same state.']);
        }
        $before = $city->only(['is_enabled', 'county_id', 'population']);
        $city->forceFill(array_filter(['is_enabled' => $data['enabled'] ?? null, 'county_id' => $data['county_id'] ?? $city->county_id, 'population' => $data['population'] ?? null], fn ($v) => $v !== null))->save();
        $this->audit($request, "Updated {$city->name}", ['old' => $before, 'attributes' => $city->only(['is_enabled', 'county_id', 'population'])]);

        return back()->with('status', 'location-saved');
    }

    public function addAlias(Request $request, City $city): RedirectResponse
    {
        $alias = trim((string) $request->validate(['alias' => ['required', 'string', 'max:120']])['alias']);
        $norm = PlaceName::normalize($alias);
        if ($norm === $city->search_name) {
            throw ValidationException::withMessages(['alias' => 'That already matches the city’s name.']);
        }
        CityAlias::query()->firstOrCreate(['city_id' => $city->id, 'search_name' => $norm], ['alias' => $alias]);
        $this->audit($request, "Added alias “{$alias}” to {$city->name}");

        return back()->with('status', 'location-saved');
    }

    public function removeAlias(Request $request, CityAlias $alias): RedirectResponse
    {
        $alias->delete();
        $this->audit($request, "Removed alias “{$alias->alias}”");

        return back()->with('status', 'location-saved');
    }

    /** Folds a duplicate into the city that stays: its name becomes an alias, then it is removed. */
    public function merge(Request $request, City $city): RedirectResponse
    {
        $into = City::query()->findOrFail((int) $request->validate(['into' => ['required', 'integer']])['into']);
        if ($into->is($city) || $into->state_id !== $city->state_id) {
            throw ValidationException::withMessages(['into' => 'Merge into a different city in the same state.']);
        }
        DB::transaction(function () use ($city, $into) {
            CityAlias::query()->where('city_id', $city->id)->get()->each(fn (CityAlias $a) => CityAlias::query()->firstOrCreate(['city_id' => $into->id, 'search_name' => $a->search_name], ['alias' => $a->alias]));
            if ($city->search_name !== $into->search_name) {
                CityAlias::query()->firstOrCreate(['city_id' => $into->id, 'search_name' => $city->search_name], ['alias' => $city->name]);
            }
            $into->forceFill(['population' => max($into->population, $city->population)])->save();
            $city->delete();
        });
        $this->audit($request, "Merged {$city->name} into {$into->name}");

        return back()->with('status', 'location-merged');
    }

    /** @param array<string, mixed> $props */
    private function audit(Request $request, string $what, array $props = []): void
    {
        activity('admin')->causedBy($request->user())->event('location-changed')->withProperties($props)->log($what);
    }
}
