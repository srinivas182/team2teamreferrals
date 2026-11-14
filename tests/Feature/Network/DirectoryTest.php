<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\Events\PublicUrlsChanged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->solar = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live', 'description' => 'Verified solar installers, one per city.']);
    $this->mortgage = Group::query()->whereHas('industry', fn ($q) => $q->where('slug', 'mortgage'))->firstOrFail();
    $this->mortgage->update(['status' => 'live']);
    $this->houston = City::where('slug', 'houston')->firstOrFail();
    $this->austin = City::where('slug', 'austin')->firstOrFail();
    // Hurricane Sun holds the Houston solar seat, with a verified Texas license and a phone number that must stay private.
    $this->team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun', 'phone' => '+14355550199', 'founded_year' => 2009]);
    app(Seats::class)->approve(app(Seats::class)->apply($this->solar, $this->team, $this->houston, 'L-1', null), null);
    DB::table('team_licenses')->insert(['team_id' => $this->team->id, 'industry_id' => $this->solar->industry_id, 'state_id' => $this->houston->state_id, 'number' => 'L-1', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
    $this->owner = User::factory()->create(['first_name' => 'Olivia', 'last_name' => 'Owner']);
    $this->solar->owners()->attach($this->owner->id);
    // Houston's county sits in a metro area, so its city page may be indexed once its seat is filled.
    $metro = DB::table('metros')->insertGetId(['cbsa_code' => '26420', 'name' => 'Houston-Pasadena-The Woodlands, TX', 'kind' => 'metro', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('county_metro')->insert(['metro_id' => $metro, 'county_id' => $this->houston->county_id]);
    Cache::flush();
});

it('lists live public groups with filters, and shows a city’s seat in each network', function () {
    $draft = Group::where('status', 'draft')->firstOrFail();
    $this->get('/groups')->assertOk()->assertInertia(fn ($p) => $p->component('Directory')
        ->where('groups', fn ($g) => collect($g)->pluck('slug')->sort()->values()->all() === collect([$this->solar->slug, $this->mortgage->slug])->sort()->values()->all())
        ->where('answer', fn ($a) => str_contains((string) $a, '1 seats are filled') || str_contains((string) $a, 'seats are filled today')));
    $this->get('/groups?industry=solar')->assertInertia(fn ($p) => $p->where('groups', fn ($g) => count($g) === 1)->where('seo.robots', fn ($r) => str_contains((string) $r, 'noindex')));
    $this->get('/groups?q=mortgage')->assertInertia(fn ($p) => $p->where('groups.0.slug', $this->mortgage->slug)->where('groups', fn ($g) => count($g) === 1));
    $this->get('/groups?city='.$this->houston->id)->assertInertia(fn ($p) => $p->where('groups', function ($g) {
        $solar = collect($g)->firstWhere('slug', $this->solar->slug);

        return $solar['citySeat']['status'] === 'filled' && $solar['citySeat']['team']['name'] === 'Hurricane Sun';
    }));
    expect(collect($this->get('/groups')->viewData('page')['props']['seo']['schema'])->pluck('@type')->all())->toContain('ItemList', 'BreadcrumbList');
    $this->get('/groups/'.$draft->slug)->assertNotFound();
});

it('serves a group page with its answer, rules, teams by state and open seats, showing only public facts', function () {
    $res = $this->get('/groups/'.$this->solar->slug)->assertOk();
    $props = $res->viewData('page')['props'];
    expect($props['answer'])->toStartWith($this->solar->name.' is the Solar referral network')->toContain('1 seat is filled in 1 state')
        ->and($props['group']['owners'])->toBe(['Olivia Owner'])
        ->and($props['seats'][0]['team'])->toMatchArray(['name' => 'Hurricane Sun', 'licenses' => ['TX'], 'foundedYear' => 2009])
        ->and($props['seats'][0]['cityId'])->toBe($this->houston->id)
        ->and(collect($props['open'])->pluck('city'))->not->toContain('Houston')->toContain('Austin');
    // Privacy: no phone, email or client data anywhere on the page.
    $json = (string) json_encode([$props['group'], $props['seats'], $props['open'], $props['answer']]);
    expect($json)->not->toContain('5550199')->not->toContain('@')->not->toContain($this->team->leader->email)->not->toContain($this->owner->email);
    // A team that keeps its profile private isn't listed.
    $this->team->forceFill(['profile_public' => false])->save();
    expect($this->get('/groups/'.$this->solar->slug)->viewData('page')['props']['seats'])->toBe([]);
});

it('serves industry by state pages, indexed only once a seat is filled there', function () {
    $tx = $this->get('/industries/solar/'.strtolower((string) DB::table('states')->where('id', $this->houston->state_id)->value('code')))->assertOk();
    expect($tx->viewData('page')['props']['seo']['robots'])->toContain('index, follow')
        ->and($tx->viewData('page')['props']['seats'][0]['team']['name'])->toBe('Hurricane Sun');
    $ut = strtolower((string) DB::table('states')->where('id', City::where('slug', 'saint-george')->value('state_id'))->value('code'));
    $empty = $this->get('/industries/solar/'.$ut)->assertOk()->viewData('page')['props'];
    expect($empty['seo']['robots'])->toContain('noindex')->and($empty['answer'])->toContain('every seat is open to claim');
    $this->get('/industries/solar/TX')->assertNotFound(); // one address per page
    $this->get('/industries/solar/zz')->assertNotFound();
    // Tier B shows its notice.
    expect($this->get('/industries/mortgage/tx')->assertOk()->viewData('page')['props']['disclosure'])->toStartWith('No referral fees');
});

it('opens each city page with the answer, indexing it only when its seat is filled or waitlisted in a large metro', function () {
    $props = $this->get('/industries/solar/tx/houston')->assertOk()->viewData('page')['props'];
    expect($props['answer'])->toBe('The verified installer for Houston, TX on Team to Team Referrals is Hurricane Sun, holding the '.$this->solar->name.' seat for Houston, TX: license verified in TX.')
        ->and($props['seo']['robots'])->toContain('index, follow');
    $business = collect($props['seo']['schema'])->firstWhere('@type', 'LocalBusiness');
    expect($business['name'])->toBe('Hurricane Sun')->and($business)->not->toHaveKey('aggregateRating'); // fewer than 3 ratings: no stars claimed
    // Austin's seat is open and nobody waits: the page works but isn't indexed.
    $austin = $this->get('/industries/solar/tx/austin')->assertOk()->viewData('page')['props'];
    expect($austin['answer'])->toContain('seat for installers on Team to Team Referrals is open')->and($austin['seo']['robots'])->toContain('noindex')
        ->and($austin['seats'][0]['seat']['status'])->toBe('open');
    DB::table('cities')->where('id', $this->austin->id)->update(['is_enabled' => false]);
    $this->get('/industries/solar/tx/austin')->assertNotFound();
    $this->get('/industries/solar/tx/nowhere')->assertNotFound();
});

it('starts a claim on a public page and ends it in a pending application', function () {
    app(Settings::class)->set('modules.prelaunch', '0');
    $this->get('/claim/'.$this->solar->slug.'/'.$this->austin->id)->assertRedirect('/register');
    expect(session('url.intended'))->toEndWith('/app/groups/'.$this->solar->slug.'/apply?city='.$this->austin->id);
    // Before launch, visitors reserve instead.
    app(Settings::class)->set('modules.prelaunch', '1');
    $this->get('/claim/'.$this->solar->slug.'/'.$this->austin->id)->assertRedirect('/reserve?industry=solar&city='.$this->austin->id);
    // A signed-in member goes straight to the application, with the city filled in, and applying leaves it pending.
    $member = User::factory()->create();
    $this->actingAs($member)->get('/claim/'.$this->solar->slug.'/'.$this->austin->id)->assertRedirect('/app/groups/'.$this->solar->slug.'/apply?city='.$this->austin->id);
    $this->get('/app/groups/'.$this->solar->slug.'/apply?city='.$this->austin->id)->assertOk()->assertInertia(fn ($p) => $p->where('city.id', $this->austin->id)->where('city.name', 'Austin, TX'));
    $this->post('/app/groups/'.$this->solar->slug.'/apply', ['city_id' => $this->austin->id, 'license_number' => 'TX-2'])->assertRedirect('/app');
    expect(DB::table('seat_applications')->where(['group_id' => $this->solar->id, 'team_id' => Team::of($member)->id])->value('status'))->toBe('pending');
});

it('refreshes the cached pages when a seat, team or group changes', function () {
    Event::fake([PublicUrlsChanged::class]);
    $seat = Seat::query()->where('primary_team_id', $this->team->id)->firstOrFail();
    $seat->update(['status' => 'grace']);
    Event::assertDispatched(PublicUrlsChanged::class, fn ($e) => in_array('/groups/'.$this->solar->slug, $e->paths, true)
        && in_array('/industries/solar/tx/houston', $e->paths, true) && in_array('/industries/solar/tx', $e->paths, true) && in_array('/t/'.$this->team->fresh()->slug, $e->paths, true));
    $this->team->update(['name' => 'Hurricane Sun Energy']);
    Event::assertDispatchedTimes(PublicUrlsChanged::class, 2);
    $this->solar->update(['description' => 'New words.']);
    Event::assertDispatched(PublicUrlsChanged::class, fn ($e) => $e->paths === ['/groups', '/groups/'.$this->solar->slug]);
});

it('lists the pages worth indexing in the sitemap, and lets the CDN cache them for visitors', function () {
    $xml = (string) $this->get('/sitemaps/directory.xml')->assertOk()->getContent();
    expect($xml)->toContain('/groups/'.$this->solar->slug)->toContain('/industries/solar/tx<')->toContain('/industries/solar/tx/houston')
        ->not->toContain('/industries/solar/tx/austin');
    expect($this->get('/groups/'.$this->solar->slug)->headers->get('Cache-Control'))->toContain('s-maxage');
    expect(Directory::inTopMetro($this->houston))->toBeTrue()->and(Directory::inTopMetro($this->austin))->toBeFalse();
});
