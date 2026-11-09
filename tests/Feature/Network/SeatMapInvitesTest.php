<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\SeatInviteNotification;
use App\Domains\Network\Seats\SeatMap;
use App\Domains\Network\Seats\Seats;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:locations:import-metros', ['file' => base_path('tests/fixtures/locations/cbsa.csv')]);
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
    $this->group = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->owner = User::factory()->create();
    $this->group->owners()->attach($this->owner->id);
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
});

it('invites teams to territories by email, and accepting claims the seat through the normal rules', function () {
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/invites', ['rows' => [
        ['email' => 'Ana@SunPath.example', 'city_id' => $this->cities[0]->id],
        ['email' => 'cole@lonestar.example', 'city_id' => $this->cities[0]->id],
    ]])->assertSessionHas('status', 'invites-sent')->assertSessionHas('inviteNote', '2 invitation(s) sent.');
    Notification::assertSentTo(new AnonymousNotifiable, SeatInviteNotification::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'ana@sunpath.example');

    $this->post('/app/group/solar-installers-network/invites', ['rows' => [['email' => 'ana@sunpath.example', 'city_id' => $this->cities[0]->id]]])->assertSessionHas('inviteNote', fn ($n) => str_contains($n, 'already invited'));

    $sent = Notification::sent(new AnonymousNotifiable, SeatInviteNotification::class);
    $urls = collect($sent)->map(fn ($n) => $n->url)->values();

    $ana = User::factory()->create(['email' => 'ana@sunpath.example']);
    $this->actingAs($ana)->post('/invites/'.basename($urls[0]).'/accept')->assertRedirect('/app')->assertSessionHas('status', 'seat-claimed');
    expect(Seat::firstOrFail()->primary->leader_id)->toBe($ana->id);

    $wrong = User::factory()->create();
    $this->actingAs($wrong)->post('/invites/'.basename($urls[1]).'/accept')->assertSessionHasErrors('invite');
    $cole = User::factory()->create(['email' => 'cole@lonestar.example']);
    $this->actingAs($cole)->post('/invites/'.basename($urls[1]).'/accept')->assertSessionHas('status', 'application-approved-backup');
    $this->actingAs($ana)->post('/invites/'.basename($urls[0]).'/accept')->assertSessionHasErrors('invite');
});

it('works for invite-only groups and expires after 14 days', function () {
    $this->group->update(['visibility' => 'invite_only']);
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/invites', ['rows' => [['email' => 'x@example.com', 'city_id' => $this->cities[0]->id]]]);
    $url = Notification::sent(new AnonymousNotifiable, SeatInviteNotification::class)->first()->url;

    auth()->logout();
    $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertInertia(fn ($p) => $p->component('Invitation')->where('invite.territory', Seat::firstOrFail()->territory_name))->assertSessionHas('url.intended');

    $this->travel(15)->days();
    $this->get(parse_url($url, PHP_URL_PATH))->assertInertia(fn ($p) => $p->where('invite', null));
});

it('only lets owners and group staff send invitations or see the map', function () {
    $this->actingAs(User::factory()->create())->post('/app/group/solar-installers-network/invites', ['rows' => [['email' => 'x@example.com', 'city_id' => $this->cities[0]->id]]])->assertForbidden();
    $this->get('/app/group/solar-installers-network/seat-map')->assertForbidden();
});

it('places seats on the map by state of play', function () {
    $s = app(Seats::class);
    $mk = fn (string $n) => Team::create(['name' => $n, 'leader_id' => User::factory()->create()->id]);
    $s->approve($s->apply($this->group, $mk('A'), $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->group, $mk('B'), $this->cities[0], 'L', null), null);
    $s->apply($this->group, $mk('C'), $this->cities[1], 'L', null);
    $points = collect(SeatMap::points($this->group))->keyBy('name');
    expect($points[$this->cities[0]->name.', UT']['state_of'])->toBe('held')->and($points[$this->cities[0]->name.', UT']['backup'])->toBe('B')
        ->and($points->firstWhere('state_of', 'open'))->not->toBeNull();

    $this->actingAs($this->owner)->get('/app/group/solar-installers-network/seat-map')->assertOk()->assertInertia(fn ($p) => $p->component('admin/groups/SeatMap')->where('counts.held', 1)->where('counts.open', 1));
});

it('fills in waitlist and metro fill on overflow demand, and tells seat holders about scheduled changes', function () {
    $s = app(Seats::class);
    $mk = fn (string $n) => Team::create(['name' => $n, 'leader_id' => User::factory()->create()->id]);
    $teams = collect(['A', 'B', 'C'])->map($mk);
    foreach ($teams as $t) {
        $s->approve($s->apply($this->group, $t, $this->cities[0], 'L', null), null);
    }
    $solar = Industry::where('slug', 'solar')->firstOrFail();
    expect(SeatMap::waitlisted($solar))->toBe(1)->and(SeatMap::metroFill($solar))->toBeGreaterThan(0)
        ->and(SeatMap::metroFill(Industry::where('slug', 'home-warranty')->firstOrFail()))->toBeNull();

    DB::table('group_scheduled_changes')->insert(['group_id' => $this->group->id, 'section' => 'pricing', 'values' => json_encode($this->group->pricing), 'effective_on' => now()->addDays(20)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('t2t:groups:scheduled');
    Notification::assertSentTo($teams[0]->leader, App\Domains\Messaging\InAppNotification::class, fn ($n) => $n->type === 'network.announcement');
});
