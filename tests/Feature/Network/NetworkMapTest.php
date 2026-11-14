<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\SeatWaitlist;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Cache::flush();
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->city = fn (string $slug) => City::where('slug', $slug)->firstOrFail();
    $this->mk = function (string $name, string $city) {
        $sv = app(Seats::class);
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $sv->approve($sv->apply($this->g, $t, ($this->city)($city), 'L', null), null);

        return $t->fresh();
    };
    $this->austin = ($this->mk)('Lone Star Solar', 'austin');
    $this->json = fn () => $this->get('/map/group/'.$this->g->slug.'.json')->assertOk()->json();
    $this->at = fn (array $map, string $name) => collect($map['points'])->firstWhere('name', $name);
});

it('maps filled places with their teams and open places with a claim link, without cookies and cached for 30 seconds', function () {
    $r = $this->get('/map/group/'.$this->g->slug.'.json')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('max-age=30')->toContain('public')
        ->and($r->headers->getCookies())->toBe([]);
    $map = $r->json();
    $austin = ($this->at)($map, 'Austin, TX');
    expect($austin)->toMatchArray(['status' => 'held', 'team' => ['name' => 'Lone Star Solar', 'slug' => $this->austin->slug], 'url' => '/industries/solar/tx/austin'])
        ->and($austin)->not->toHaveKey('claim');
    $houston = ($this->at)($map, 'Houston, TX');
    expect($houston['status'])->toBe('open')->and($houston['claim'])->toBe('/claim/'.$this->g->slug.'/'.($this->city)('houston')->id)
        ->and($map['counts']['held'])->toBe(1)->and($map['counts']['open'])->toBeGreaterThan(0);
    // Clicking "Claim this city" goes on to sign-up (or straight to the application when signed in).
    $this->get($houston['claim'])->assertRedirect();
    $this->actingAs(User::factory()->create())->get($houston['claim'])->assertRedirect('/app/groups/'.$this->g->slug.'/apply?city='.($this->city)('houston')->id);
    // Private and unlaunched groups have no public map.
    $this->g->update(['status' => 'draft']);
    $this->get('/map/group/'.$this->g->slug.'.json')->assertNotFound();
});

it('shows seat changes straight away: a new seat, a waitlist, a lapsed seat, a private profile', function () {
    expect(($this->at)(($this->json)(), 'Houston, TX')['status'])->toBe('open');
    $houston = ($this->mk)('Hurricane Sun', 'houston');
    expect(($this->at)(($this->json)(), 'Houston, TX'))->toMatchArray(['status' => 'held', 'team' => ['name' => 'Hurricane Sun', 'slug' => $houston->slug]]);

    // The holder's profile goes private: the place drops off the map (never named, never offered as open).
    $houston->forceFill(['profile_public' => false])->save();
    expect(($this->at)(($this->json)(), 'Houston, TX'))->toBeNull();

    // The Austin seat lapses with a team waiting: it shows as open, with a waitlist.
    $seat = Seat::where(['group_id' => $this->g->id, 'primary_team_id' => $this->austin->id])->firstOrFail();
    SeatWaitlist::create(['seat_id' => $seat->id, 'team_id' => $houston->id, 'score' => 10, 'joined_at' => now()]);
    $seat->update(['status' => 'inactive', 'primary_team_id' => null]);
    expect(($this->at)(($this->json)(), 'Austin, TX'))->toMatchArray(['status' => 'waitlist', 'waitlist' => 1]);

    // Anything else (no event) is picked up within the 30-second cache.
    Illuminate\Support\Facades\DB::table('seat_waitlist')->delete();
    $this->travel(31)->seconds();
    expect(($this->at)(($this->json)(), 'Austin, TX')['status'])->toBe('open');
});

it('maps a whole industry across its public groups, on its hub page and as data', function () {
    $map = $this->get('/map/industry/solar.json')->assertOk()->json();
    expect($map['title'])->toBe('Solar')->and(($this->at)($map, 'Austin, TX'))->toMatchArray(['group' => $this->g->name, 'groupSlug' => $this->g->slug]);
    $this->get('/map/industry/no-such-industry.json')->assertNotFound();
    $this->get('/groups/'.$this->g->slug)->assertOk()->assertInertia(fn ($p) => $p->where('map.counts.held', 1)->has('map.points'));
});

it('can be embedded on any website, with no cookies and links back to the network', function () {
    $r = $this->get('/embed/map/group/'.$this->g->slug)->assertOk()->assertSee('t2t-map-data', false)->assertSee('Lone Star Solar')
        ->assertSee(url('/groups/'.$this->g->slug), false);
    expect((string) $r->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *')
        ->and($r->headers->has('X-Frame-Options'))->toBeFalse()
        ->and($r->headers->getCookies())->toBe([]);
    $this->get('/embed/map/industry/solar')->assertOk()->assertSee(url('/industries/solar'), false);
    $this->get('/embed/map/group/nope')->assertNotFound();
});

it('shows a team its own networks on the dashboard, its seats marked even with a private profile', function () {
    $this->austin->forceFill(['profile_public' => false])->save();
    $this->actingAs($this->austin->leader)->get('/app/dashboard')->assertInertia(fn ($p) => $p->has('maps', 1)
        ->where('maps.0.src', '/map/group/'.$this->g->slug.'.json')
        ->where('maps.0.mine.0.name', 'Austin, TX')
        ->where('maps.0.mine.0.key', $this->g->id.':city:'.($this->city)('austin')->id));
    $other = Team::forLeader(User::factory()->create());
    $this->actingAs($other->leader)->get('/app/dashboard')->assertInertia(fn ($p) => $p->has('maps', 0));
});
