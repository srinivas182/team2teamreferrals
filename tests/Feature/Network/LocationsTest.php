<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Locations\Models\County;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Network\Locations\PlaceName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]));

function locAdmin(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo('locations.manage');

    return $u;
}

it('normalises place names so spelling variants match', function (string $a, string $b) {
    expect(PlaceName::normalize($a))->toBe(PlaceName::normalize($b));
})->with([['St. George', 'Saint George'], ['Ft Worth', 'Fort Worth'], ['Mt. Pleasant', 'Mount Pleasant'], ['Coeur d’Alene', "Coeur d'Alene"]]);

it('imports states, counties and cities, with Puerto Rico switched off', function () {
    expect(State::count())->toBe(3)->and(County::count())->toBe(4)->and(City::count())->toBe(6)
        ->and(State::where('code', 'PR')->value('is_enabled'))->toBeFalse()
        ->and(City::where('name', 'Saint George')->first()->county->name)->toBe('Washington');
});

it('finds cities as you type, biggest first, by any spelling', function () {
    $this->getJson('/locations/cities?q=st geo')->assertOk()->assertJsonPath('cities.0.label', 'Saint George, UT');
    $this->getJson('/locations/cities?q=ft wor')->assertJsonPath('cities.0.name', 'Fort Worth');
    $this->getJson('/locations/cities?q=ho')->assertJsonPath('cities.0.name', 'Houston');
    $this->getJson('/locations/cities?q=hu&state=TX')->assertJsonCount(0, 'cities');
    $this->getJson('/locations/cities?q=hu&state=UT')->assertJsonPath('cities.0.name', 'Hurricane');
    $this->getJson('/locations/cities?q=san juan')->assertJsonCount(0, 'cities'); // Puerto Rico disabled
});

it('keeps admin decisions when the data is imported again', function () {
    $austin = City::where('name', 'Austin')->firstOrFail();
    $travis = County::where('fips', '48453')->firstOrFail();
    $austin->forceFill(['county_id' => $travis->id, 'is_enabled' => false])->save();
    State::where('code', 'PR')->update(['is_enabled' => true]);

    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    $austin->refresh();
    expect($austin->county_id)->toBe($travis->id)->and($austin->is_enabled)->toBeFalse()
        ->and(State::where('code', 'PR')->value('is_enabled'))->toBeTrue()->and(City::count())->toBe(6);
});

it('imports Census metro areas with their counties, skipping title and footnote rows', function () {
    Artisan::call('t2t:locations:import-metros', ['file' => base_path('tests/fixtures/locations/cbsa.csv')]);
    expect(DB::table('metros')->count())->toBe(2)->and(DB::table('county_metro')->count())->toBe(3)
        ->and(DB::table('metros')->where('cbsa_code', '12420')->value('kind'))->toBe('metro');
});

it('lets location admins disable cities, fix counties, add aliases and merge duplicates', function () {
    $this->actingAs(User::factory()->create())->get('/admin/locations')->assertForbidden();
    $admin = locAdmin();
    $austin = City::where('name', 'Austin')->firstOrFail();
    $travis = County::where('fips', '48453')->firstOrFail();

    $this->actingAs($admin)->get('/admin/locations?state=TX')->assertOk()->assertInertia(fn ($p) => $p->component('admin/locations/Index')->has('cities', 3));
    $this->patch("/admin/locations/cities/{$austin->id}", ['county_id' => $travis->id])->assertSessionHasNoErrors();
    expect($austin->fresh()->county_id)->toBe($travis->id);
    $this->patch("/admin/locations/cities/{$austin->id}", ['county_id' => County::where('fips', '49053')->value('id')])->assertSessionHasErrors('county_id');

    $houston = City::where('name', 'Houston')->firstOrFail();
    $this->post("/admin/locations/cities/{$houston->id}/aliases", ['alias' => 'H-Town'])->assertSessionHasNoErrors();
    $this->getJson('/locations/cities?q=h-town')->assertJsonPath('cities.0.name', 'Houston');

    $dupe = City::create(['state_id' => $houston->state_id, 'name' => 'Houston City', 'slug' => 'houston-city', 'search_name' => 'houston city', 'population' => 10]);
    $this->post("/admin/locations/cities/{$dupe->id}/merge", ['into' => $houston->id])->assertSessionHas('status', 'location-merged');
    expect(City::find($dupe->id))->toBeNull();
    $this->getJson('/locations/cities?q=houston city')->assertJsonPath('cities.0.name', 'Houston');
});

it('loads the full US dataset', function () {
    Artisan::call('t2t:locations:import');
    expect(City::count())->toBeGreaterThan(29000)->and(County::count())->toBeGreaterThan(3100)
        ->and(State::where('is_enabled', true)->count())->toBe(51);
    // Ranking by population across the real data: the big city wins over its namesakes.
    $this->getJson('/locations/cities?q=houston')->assertJsonPath('cities.0.label', 'Houston, TX');
    $this->getJson('/locations/cities?q=st george')->assertJsonPath('cities.0.label', 'Saint George, UT');
});
