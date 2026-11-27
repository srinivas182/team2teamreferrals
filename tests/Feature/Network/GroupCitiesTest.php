<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\GroupCities;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get(); // 0 Saint George UT, 1 Hurricane UT, 2 Austin TX, 3 Houston TX, 4 Fort Worth TX
    $this->owner = User::factory()->create();
    DB::table('group_owners')->insert(['group_id' => $this->g->id, 'user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
});

it('lets any city join by default, and only listed cities once the owner limits it', function () {
    $hurricane = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $hurricane, $this->cities[1], 'L', null), null);
    expect(GroupCities::limited($this->g))->toBeFalse()->and(GroupCities::summary($this->g))->toBeNull();

    $this->actingAs($this->owner)->get('/app/group/solar-installers-network/cities')->assertOk()->assertInertia(fn ($p) => $p->component('admin/groups/Cities')->where('limited', false));
    $this->getJson('/app/group/solar-installers-network/cities/state/tx')->assertOk()->assertJsonFragment(['name' => 'Austin']);
    $this->put('/app/group/solar-installers-network/cities', ['limited' => true, 'cities' => []])->assertSessionHasErrors('cities');
    $this->put('/app/group/solar-installers-network/cities', ['limited' => true, 'cities' => [$this->cities[2]->id, $this->cities[3]->id]])->assertSessionHas('status', 'group-cities-saved');
    $this->get('/app/group/solar-installers-network/cities')->assertInertia(fn ($p) => $p->where('limited', true)->has('cities.TX', 2)->where('heldOutside', 1)); // Hurricane keeps its seat

    $austin = Team::forLeader(User::factory()->create());
    $utah = Team::forLeader(User::factory()->create());
    $this->actingAs($utah->leader)->post('/app/groups/solar-installers-network/apply', ['city_id' => $this->cities[0]->id, 'license_number' => 'L'])->assertSessionHasErrors('city_id');
    $this->actingAs($austin->leader)->post('/app/groups/solar-installers-network/apply', ['city_id' => $this->cities[2]->id, 'license_number' => 'L'])->assertSessionHasNoErrors();
    $this->get('/app/groups/solar-installers-network/apply')->assertInertia(fn ($p) => $p->where('openCities', fn ($s) => str_contains($s, 'Austin, TX') || str_contains($s, 'Houston, TX')));
    $this->g->update(['status' => 'live']);
    $this->get('/groups/solar-installers-network')->assertInertia(fn ($p) => $p->where('openCities', fn ($s) => str_starts_with((string) $s, 'Open to teams in')));

    // Back to all cities.
    $this->actingAs($this->owner)->put('/app/group/solar-installers-network/cities', ['limited' => false])->assertSessionHasNoErrors();
    expect(GroupCities::limited($this->g))->toBeFalse()->and(DB::table('group_cities')->count())->toBe(0);
});

it('keeps the cities setting to owners and group staff', function () {
    $this->actingAs(User::factory()->create())->get('/app/group/solar-installers-network/cities')->assertForbidden();
    $this->put('/app/group/solar-installers-network/cities', ['limited' => true, 'cities' => [1]])->assertForbidden();
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin)->get('/admin/groups/solar-installers-network/cities')->assertOk();
});
