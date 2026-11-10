<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->city = City::where('is_enabled', true)->orderBy('id')->firstOrFail();
});

function reserveAndToken($test, array $over = []): string
{
    $test->post('/reserve', [...['industry' => 'solar', 'city_id' => $test->city->id, 'name' => 'Ana Ruiz', 'business' => 'SunPath Solar', 'email' => 'ana@sunpath.example', 'on_wall' => true], ...$over])->assertSessionHas('status', 'reservation-sent');
    $url = Notification::sent(new AnonymousNotifiable, App\Domains\Network\Prelaunch\ConfirmReservationNotification::class)->last()?->url;

    return basename((string) $url);
}

it('shows the reserve page with countdown, counters and founding wall', function () {
    app(Settings::class)->set('prelaunch.launch_at', '2027-03-01T15:00:00Z');
    $this->get('/reserve')->assertOk()->assertInertia(fn ($p) => $p->component('Reserve')->where('launchAt', '2027-03-01T15:00:00Z')->where('counters.reservations', 0)->has('industries', 46));
});

it('checks availability, then confirms by email and claims as a founding application', function () {
    $this->getJson("/reserve/check?industry=solar&city_id={$this->city->id}")->assertOk()->assertJsonPath('open', true);
    $token = reserveAndToken($this);
    expect(DB::table('reservations')->value('status'))->toBe('pending');

    $this->get("/reserve/confirm/{$token}")->assertOk()->assertInertia(fn ($p) => $p->component('ReserveConfirmed')->where('status', 'confirmed'))->assertSessionHas('url.intended');
    $this->getJson("/reserve/check?industry=solar&city_id={$this->city->id}")->assertJsonPath('open', false);

    $this->actingAs(User::factory()->create())->post("/reserve/claim/{$token}")->assertSessionHasErrors('reservation');
    $ana = User::factory()->create(['email' => 'ana@sunpath.example', 'first_name' => 'Ana', 'last_name' => 'Ruiz']);
    $this->actingAs($ana)->post("/reserve/claim/{$token}")->assertRedirect('/app')->assertSessionHas('status', 'reservation-claimed');

    $team = Team::of($ana);
    expect($team->name)->toBe('SunPath Solar')
        ->and(DB::table('seat_applications')->where('team_id', $team->id)->value('status'))->toBe('pending')
        ->and(DB::table('seat_events')->where('event', 'reserved')->exists())->toBeTrue()
        ->and(DB::table('reservations')->value('status'))->toBe('claimed');
    $this->post("/reserve/claim/{$token}")->assertSessionHasErrors('reservation');
});

it('counts confirmed reservations and shows only first names or businesses on the wall', function () {
    $t1 = reserveAndToken($this);
    $t2 = reserveAndToken($this, ['email' => 'cole@example.com', 'name' => 'Cole Brennan', 'business' => '', 'industry' => 'roofing']);
    reserveAndToken($this, ['email' => 'hidden@example.com', 'industry' => 'hvac', 'on_wall' => false]);
    $this->get("/reserve/confirm/{$t1}");
    $this->get("/reserve/confirm/{$t2}");
    $this->get('/reserve')->assertInertia(fn ($p) => $p->where('counters.reservations', 2)->where('counters.cities', 1)->has('wall', 2)
        ->where('wall', fn ($w) => collect($w)->pluck('name')->sort()->values()->all() === ['Cole', 'SunPath Solar']));
});

it('refuses duplicates and bots, and disappears when the pre-launch module is off', function () {
    reserveAndToken($this);
    $this->post('/reserve', ['industry' => 'solar', 'city_id' => $this->city->id, 'name' => 'A', 'email' => 'ana@sunpath.example'])->assertSessionHasErrors('email');
    $this->post('/reserve', ['industry' => 'solar', 'city_id' => $this->city->id, 'name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam'])->assertSessionHasErrors('website');
    app(Settings::class)->set('modules.prelaunch', '0');
    $this->get('/reserve')->assertNotFound();
});

it('refuses an offer that would take a company over its cap', function () {
    $group = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $group->update(['rules' => [...$group->rules, 'company_cap_pct' => 10]]);
    $admin = User::factory()->create();
    $company = Company::create(['name' => 'BigCo', 'admin_id' => $admin->id]);
    $s = app(Seats::class);
    $cities = City::whereHas('state', fn ($q) => $q->where('code', 'UT'))->where('is_enabled', true)->orderBy('id')->get();
    $mk = function (string $n) use ($company) {
        $t = Team::forLeader(User::factory()->create());
        $t->forceFill(['name' => $n, 'company_id' => $company->id])->save();

        return $t;
    };
    $s->approve($s->apply($group->fresh(), $mk('Branch A'), $cities[1], 'L', null), null);
    $holder = Team::forLeader(User::factory()->create());
    $backup = $mk('Branch B');
    $s->approve($s->apply($group->fresh(), $holder, $cities[0], 'L', null), null);
    $s->approve($s->apply($group->fresh(), $backup, $cities[0], 'L', null), null);
    $seat = Seat::where('territory_id', $cities[0]->id)->firstOrFail();
    $s->release($seat, 'test');
    $offer = DB::table('seat_offers')->where('team_id', $backup->id)->firstOrFail();
    expect(fn () => $s->acceptOffer((int) $offer->id, $backup))->toThrow(Illuminate\Validation\ValidationException::class);
});
