<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\IndustryLicensing;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
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
    $this->solar = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->city = City::where('is_enabled', true)->orderBy('id')->firstOrFail();
    $this->owner = User::factory()->create();
    $this->solar->owners()->attach($this->owner->id);
    $this->platform = User::factory()->create();
    $this->platform->assignRole('super_admin');
    $this->seats = app(Seats::class);
});

function seatTeam(string $name): Team
{
    $u = User::factory()->create();

    return Team::create(['name' => $name, 'leader_id' => $u->id]);
}

function toldAbout(User $u, string $type): bool
{
    return Notification::sent($u, InAppNotification::class)->contains(fn ($n) => $n->type === $type);
}

it('gives the first approved team the seat on a free trial, the second the backup, then the waitlist', function () {
    [$a, $b, $c] = [seatTeam('SunPath'), seatTeam('Lone Star'), seatTeam('Gulf Coast')];
    $ids = collect([$a, $b, $c])->map(fn ($t) => $this->seats->apply($this->solar, $t, $this->city, 'LIC-1', null));
    expect($ids->map(fn ($id) => $this->seats->approve($id, $this->owner))->all())->toBe(['primary', 'backup', 'waitlist']);

    $seat = Seat::firstOrFail();
    expect($seat->status)->toBe('trial')->and($seat->primary_team_id)->toBe($a->id)->and($seat->backup_team_id)->toBe($b->id)
        ->and($seat->founding)->toBeTrue()->and($seat->trial_ends_at->toDateString())->toBe(now()->addMonths(3)->toDateString())
        ->and($seat->waitlist()->count())->toBe(1)->and($seat->receiver()?->id)->toBe($a->id);
    expect(toldAbout($a->leader, 'seat.decision'))->toBeTrue()->and(toldAbout($this->owner, 'seat.update'))->toBeTrue()->and(toldAbout($this->platform, 'seat.update'))->toBeTrue();
    expect(DB::table('seat_events')->where('seat_id', $seat->id)->pluck('event')->all())->toContain('applied', 'claimed', 'backup', 'waitlisted');
});

it('refuses duplicate applications, invite-only groups and missing or malformed licenses', function () {
    $t = seatTeam('SunPath');
    $this->seats->apply($this->solar, $t, $this->city, 'LIC-1', null);
    expect(fn () => $this->seats->apply($this->solar, $t, $this->city, 'LIC-1', null))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(fn () => $this->seats->apply($this->solar, seatTeam('B'), $this->city, null, null))->toThrow(Illuminate\Validation\ValidationException::class);

    $state = State::findOrFail($this->city->state_id);
    IndustryLicensing::create(['industry_id' => $this->solar->industry_id, 'state_id' => $state->id, 'required' => true, 'number_pattern' => '^[0-9]{5}$']);
    expect(fn () => $this->seats->apply($this->solar, seatTeam('C'), $this->city, 'ABC', null))->toThrow(Illuminate\Validation\ValidationException::class);
    expect($this->seats->apply($this->solar, seatTeam('D'), $this->city, '12345', null))->toBeInt();

    $this->solar->update(['visibility' => 'invite_only']);
    expect(fn () => $this->seats->apply($this->solar->fresh(), seatTeam('E'), $this->city, '12345', null))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('approves automatically when the group allows it', function () {
    $this->solar->update(['rules' => [...$this->solar->rules, 'auto_approve' => true]]);
    $this->seats->apply($this->solar->fresh(), seatTeam('SunPath'), $this->city, 'LIC-1', null);
    expect(Seat::firstOrFail()->status)->toBe('trial')->and(DB::table('seat_applications')->value('status'))->toBe('approved');
});

it('runs the whole non-payment lifecycle and tells everyone at every stage', function () {
    [$a, $b, $c] = [seatTeam('SunPath'), seatTeam('Lone Star'), seatTeam('Gulf Coast')];
    foreach ([$a, $b, $c] as $t) {
        $this->seats->approve($this->seats->apply($this->solar, $t, $this->city, 'LIC-1', null), $this->owner);
    }
    $seat = Seat::firstOrFail();

    $this->seats->paymentFailed($seat);
    expect($seat->fresh()->status)->toBe('grace')->and(toldAbout($a->leader, 'seat.payment'))->toBeTrue();

    $this->travel(8)->days();
    $this->seats->tick();
    $seat->refresh();
    expect($seat->status)->toBe('inactive')->and($seat->receiver()?->id)->toBe($b->id);

    $this->travel(23)->days();
    $this->seats->tick();
    $seat->refresh();
    expect($seat->status)->toBe('open')->and($seat->primary_team_id)->toBeNull()->and($seat->founding)->toBeFalse();
    $offer = DB::table('seat_offers')->where('seat_id', $seat->id)->first();
    expect($offer->team_id)->toBe($b->id)->and($offer->status)->toBe('pending');
    expect(Notification::sent($b->leader, InAppNotification::class)->contains(fn ($n) => $n->type === 'seat.offered'))->toBeTrue();

    $this->seats->acceptOffer((int) $offer->id, $b);
    $seat->refresh();
    expect($seat->primary_team_id)->toBe($b->id)->and($seat->status)->toBe('trial')->and($seat->backup_team_id)->toBe($c->id)
        ->and($seat->waitlist()->count())->toBe(0);
    expect(DB::table('seat_events')->where('seat_id', $seat->id)->pluck('event')->all())->toContain('payment_failed', 'inactive', 'released', 'offered', 'claimed');
});

it('reactivates instantly when the team pays before release', function () {
    $a = seatTeam('SunPath');
    $this->seats->approve($this->seats->apply($this->solar, $a, $this->city, 'LIC-1', null), null);
    $seat = Seat::firstOrFail();
    $this->seats->paymentFailed($seat);
    $this->travel(10)->days();
    $this->seats->tick();
    $this->seats->paymentSucceeded($seat->fresh());
    expect($seat->fresh()->status)->toBe('active')->and($seat->fresh()->founding)->toBeTrue();
    $this->travel(30)->days();
    $this->seats->tick();
    expect($seat->fresh()->primary_team_id)->toBe($a->id);
});

it('passes an unclaimed or declined offer to the next team, and leaves the seat open when nobody is waiting', function () {
    [$a, $b, $c, $d] = [seatTeam('A'), seatTeam('B'), seatTeam('C'), seatTeam('D')];
    foreach ([$a, $b, $c, $d] as $t) {
        $this->seats->approve($this->seats->apply($this->solar, $t, $this->city, 'LIC-1', null), null);
    }
    $seat = Seat::firstOrFail();
    $this->seats->release($seat, 'test');
    expect(DB::table('seat_offers')->latest('id')->value('team_id'))->toBe($b->id);

    $this->travel(73)->hours();
    $this->seats->tick();
    $next = DB::table('seat_offers')->where('status', 'pending')->first();
    expect($next->team_id)->toBe($c->id);

    $this->seats->declineOffer((int) $next->id, $c);
    $last = DB::table('seat_offers')->where('status', 'pending')->first();
    expect($last->team_id)->toBe($d->id);
    $this->seats->declineOffer((int) $last->id, $d);
    expect(DB::table('seat_offers')->where('status', 'pending')->count())->toBe(0)->and($seat->fresh()->status)->toBe('open');
    expect(fn () => $this->seats->acceptOffer((int) $next->id, $c))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('maps a city to its county or state for industries with bigger territories', function () {
    $title = Group::where('slug', 'title-escrow-network')->firstOrFail();
    $this->seats->apply($title, seatTeam('T'), $this->city, 'LIC-1', null);
    $warranty = Group::where('slug', 'home-warranty-network')->firstOrFail();
    $this->seats->apply($warranty, seatTeam('W'), $this->city, 'LIC-1', null);
    expect(Seat::where('group_id', $title->id)->value('territory_type'))->toBe('county')
        ->and(Seat::where('group_id', $warranty->id)->value('territory_type'))->toBe('state');
});

it('lets members apply and owners decide through the screens, and keeps others out', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->post('/app/groups/solar-installers-network/apply', ['city_id' => $this->city->id, 'license_number' => 'LIC-1'])->assertRedirect('/app')->assertSessionHas('status', 'seat-applied');
    $id = (int) DB::table('seat_applications')->value('id');

    $this->actingAs(User::factory()->create())->post("/app/group/solar-installers-network/applications/{$id}/approve")->assertForbidden();
    $this->actingAs($this->owner)->post("/app/group/solar-installers-network/applications/{$id}/decline", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/app/group/solar-installers-network/applications/{$id}/approve")->assertSessionHas('status', 'application-approved-primary');
    $this->get('/app/group/solar-installers-network/applications')->assertOk()->assertInertia(fn ($p) => $p->component('admin/groups/Applications')->where('applications.0.status', 'approved'));
    expect(Seat::firstOrFail()->primary->leader_id)->toBe($member->id);
});

it('shows real seat counts in the group admin', function () {
    $this->seats->approve($this->seats->apply($this->solar, seatTeam('A'), $this->city, 'LIC-1', null), null);
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin)->get('/admin/groups/solar-installers-network')->assertInertia(fn ($p) => $p->where('health.seats', 1)->where('affected.trial', 1)->has('seats', 1)->where('group.rules.grace_days', 7));
});
