<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Licenses\Licenses;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Prelaunch\ConfirmReservationNotification;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
});

/** Reserve, confirm and claim; returns [team, share id]. */
function foundingMember($test, string $email, string $industry, int $cityIdx, ?string $ref = null): array
{
    $test->post('/reserve', ['industry' => $industry, 'city_id' => $test->cities[$cityIdx]->id, 'name' => 'Pro '.$email, 'business' => 'Biz '.$email, 'email' => $email, 'ref' => $ref]);
    $token = basename((string) Notification::sent(new AnonymousNotifiable, ConfirmReservationNotification::class)->last()->url);
    $test->get("/reserve/confirm/{$token}");
    $u = User::factory()->create(['email' => $email]);
    $test->actingAs($u)->post("/reserve/claim/{$token}");
    auth()->logout();

    return [Team::of($u), (string) DB::table('reservations')->where('email', $email)->value('share_id')];
}

it('shows live map points and the state race on the reserve page', function () {
    foundingMember($this, 'a@example.com', 'solar', 0);
    foundingMember($this, 'b@example.com', 'roofing', 1);
    $this->get('/reserve')->assertInertia(fn ($p) => $p->has('points', 2)->where('race.0.count', 2)->where('points.0.industry', fn ($v) => in_array($v, ['solar', 'roofing'], true)));
});

it('gives every founding member a share page that previews nicely and works as an invite', function () {
    [, $share] = foundingMember($this, 'ana@example.com', 'solar', 0);
    $this->get("/reserve/c/{$share}")->assertOk()->assertInertia(fn ($p) => $p->component('ReserveShare')->where('share.business', 'Biz ana@example.com')
        ->where('seo.title', fn ($t) => str_contains($t, 'reserved')));
    $this->get('/reserve/c/'.str_repeat('Z', 26))->assertNotFound();
});

it('moves inviters up when the pros they invited claim and get a license verified', function () {
    [$ana, $share] = foundingMember($this, 'ana@example.com', 'solar', 0);
    [$cole] = foundingMember($this, 'cole@example.com', 'roofing', 1, $share);
    expect(DB::table('reservations')->where('email', 'cole@example.com')->value('referred_by'))->not->toBeNull()
        ->and(Licenses::verifiedInvites($ana))->toBe(0);

    $staff = User::factory()->create();
    $id = app(Licenses::class)->add($cole, App\Domains\Network\Industries\Models\Industry::where('slug', 'roofing')->firstOrFail(), $this->cities[1]->state_id, 'R1', null, null, null);
    app(Licenses::class)->decide($id, $staff, true, null);
    expect(Licenses::verifiedInvites($ana))->toBe(1)->and(Licenses::rankScore($ana))->toBeGreaterThanOrEqual(15);
});

it('ignores unknown or unconfirmed invite codes', function () {
    foundingMember($this, 'x@example.com', 'solar', 0, str_repeat('A', 26));
    expect(DB::table('reservations')->where('email', 'x@example.com')->value('referred_by'))->toBeNull();
});

it('never takes seats or reservations in switched-off places', function () {
    $pr = City::whereHas('state', fn ($q) => $q->where('code', 'PR'))->firstOrFail();
    $this->post('/reserve', ['industry' => 'solar', 'city_id' => $pr->id, 'name' => 'A', 'email' => 'pr@example.com'])->assertSessionHasErrors('city_id');
    $this->getJson("/reserve/check?industry=solar&city_id={$pr->id}")->assertStatus(422);
    expect(DB::table('reservations')->count())->toBe(0);
});
