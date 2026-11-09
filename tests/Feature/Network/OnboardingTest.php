<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Licenses\Licenses;
use App\Domains\Network\Onboarding\ProfileStrength;
use App\Domains\Network\Seats\Models\Team;
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
});

it('scores profile strength from what the team has filled in', function () {
    $t = Team::forLeader(User::factory()->create());
    expect(ProfileStrength::for($t)['score'])->toBe(0);
    $t->update(['name' => 'SunPath Solar', 'bio' => str_repeat('Residential solar installers. ', 4), 'service_area' => 'Austin', 'website' => 'https://sunpath.example']);
    expect(ProfileStrength::for($t)['score'])->toBe(45);
    DB::table('team_licenses')->insert(['team_id' => $t->id, 'industry_id' => 1, 'state_id' => 1, 'number' => 'X', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
    expect(ProfileStrength::for($t)['score'])->toBe(70)->and(Licenses::rankScore($t))->toBe(60 + 21 + 10);
});

it('walks new members from sign-up to first seat on the portal home', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/app')->assertOk()->assertInertia(fn ($p) => $p->component('app/Home')->has('checklist', 7)->where('checklist.5.label', 'Apply for your city')->where('checklist.5.href', '/app/groups')->where('strength.score', 0));
});

it('lists public networks to join and hides private ones', function () {
    Group::where('slug', 'solar-installers-network')->update(['is_private' => true]);
    $this->actingAs(User::factory()->create())->get('/app/groups')->assertOk()->assertInertia(fn ($p) => $p->component('app/groups/Index')->has('groups', 45)->where('groups.0.price', 10));
});

it('previews the team’s public profile', function () {
    $u = User::factory()->create();
    Team::forLeader($u)->update(['name' => 'SunPath Solar']);
    $this->actingAs($u)->get('/app/team/preview')->assertOk()->assertInertia(fn ($p) => $p->component('app/TeamPreview')->where('team.name', 'SunPath Solar')->has('seats', 0));
});

it('nudges new members 2 and 7 days after sign-up, once each, unless they have applied', function () {
    $quiet = User::factory()->create();
    $applied = User::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $t = Team::forLeader($applied);
    $g = Group::firstOrFail();
    $seat = DB::table('seats')->insertGetId(['group_id' => $g->id, 'territory_type' => 'state', 'territory_id' => 1, 'territory_name' => 'Utah', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('seat_applications')->insert(['group_id' => $g->id, 'team_id' => $t->id, 'seat_id' => $seat, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    $this->travel(2)->days();
    Artisan::call('t2t:onboarding:nudges');
    Artisan::call('t2t:onboarding:nudges');
    $nudges = fn (User $u) => Notification::sent($u, InAppNotification::class)->filter(fn ($n) => $n->type === 'onboarding.nudge')->count();
    expect($nudges($quiet))->toBe(1)->and($nudges($applied))->toBe(0)->and($nudges($staff))->toBe(0);

    $this->travel(5)->days();
    Artisan::call('t2t:onboarding:nudges');
    expect($nudges($quiet))->toBe(2);
});
