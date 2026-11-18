<?php

/*
 * Sprint 53b: load-test support and the scaling fixes the load test found.
 */

use App\Domains\Identity\Models\User;
use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('makes and removes load-test members, and refuses on a production install unless forced', function () {
    app()->detectEnvironment(fn () => 'production');
    expect(Artisan::call('t2t:loadtest:seed', ['--members' => 3]))->toBe(1);
    expect(DB::table('users')->where('email', 'like', '%@loadtest.invalid')->count())->toBe(0);
    app()->detectEnvironment(fn () => 'testing');
    Artisan::call('t2t:loadtest:seed', ['--members' => 3]);
    Artisan::call('t2t:loadtest:seed', ['--members' => 3]); // again: nothing duplicated
    $u = User::query()->where('email', 'loadtest2@loadtest.invalid')->firstOrFail();
    expect(DB::table('users')->where('email', 'like', '%@loadtest.invalid')->count())->toBe(3)
        ->and($u->phone_verified_at)->not->toBeNull()->and(Team::of($u))->not->toBeNull()
        ->and(Illuminate\Support\Facades\Hash::check('load-test-2026', (string) $u->password))->toBeTrue();
    Artisan::call('t2t:loadtest:seed', ['--clean' => true]);
    expect(DB::table('users')->where('email', 'like', '%@loadtest.invalid')->count())->toBe(0);
});

it('lets load generators past per-visitor limits on staging only, and keeps named limiters working', function () {
    Cache::flush();
    $hit = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->get('/.well-known/security.txt')->getStatusCode();
    // Sixty a minute from one address, then refused.
    foreach (range(1, 60) as $i) {
        $hit();
    }
    expect($hit())->toBe(429);
    config(['app.load_test_ips' => '10.20.30.40']);
    expect($hit())->not->toBe(429);
    app()->detectEnvironment(fn () => 'production');
    expect($hit())->toBe(429);
    app()->detectEnvironment(fn () => 'testing');
    // A named limiter ("throttle:api") still resolves.
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('builds a network page with the same number of queries however many seats it has', function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    $g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $cities = City::query()->select('cities.*')->join('states', 'states.id', '=', 'cities.state_id')->where('states.is_enabled', true)
        ->where('cities.is_enabled', true)->orderByDesc('cities.population')->get(); // every city in the test fixtures
    $count = function () use ($g): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/groups/'.$g->slug)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $seat = function (City $c) use ($g): void {
        $team = Team::forLeader(User::factory()->create());
        $team->forceFill(['profile_public' => true])->save();
        app(Seats::class)->approve(app(Seats::class)->apply($g, $team, $c, 'L', null), null);
    };
    $queries = function (callable $f): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $f();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    // Open places: the same queries whether it lists two places or all of them.
    expect($queries(fn () => Directory::openPlaces($g->fresh(), 2)))->toBe($queries(fn () => Directory::openPlaces($g->fresh(), 50)));
    $cities->take(1)->each($seat);
    $few = $count();
    $seatsFew = $queries(fn () => Directory::groupSeats($g->fresh()));
    $cities->slice(1)->each($seat);
    $many = $count();
    // Filled seats: the same queries for one seat or five.
    expect($queries(fn () => Directory::groupSeats($g->fresh())))->toBe($seatsFew);
    expect(count($this->get('/groups/'.$g->slug)->viewData('page')['props']['seats']))->toBe($cities->count())->and($cities->count())->toBeGreaterThanOrEqual(4)
        ->and($many)->toBeLessThanOrEqual($few); // fewer open places to list can only mean fewer queries
});
