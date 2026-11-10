<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Onboarding\ProfileStrength;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Network\Training\Training;
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
    $this->t = app(Training::class);
    $this->solar = Industry::where('slug', 'solar')->firstOrFail();
    $this->group = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->city = City::where('is_enabled', true)->orderBy('id')->firstOrFail();
});

function passTraining(Training $t, User $u, Industry $i): void
{
    $qs = json_decode((string) $t->module($i)->questions, true);
    $t->submit($u, $t->module($i), array_column($qs, 'answer'));
}

it('lets admins edit training with checks, ask for re-training, and see completion per group', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $m = $this->t->module($this->solar);
    $payload = ['pass_mark' => 80, 'retrain' => false, 'lessons' => json_decode((string) $m->lessons, true), 'questions' => json_decode((string) $m->questions, true)];

    $bad = $payload;
    $bad['questions'][0]['why'] = '';
    $this->actingAs($admin)->put('/admin/industries/solar/training', $bad)->assertSessionHasErrors('questions.0.why');
    $bad = $payload;
    $bad['questions'][0]['answer'] = 9;
    $this->put('/admin/industries/solar/training', $bad)->assertSessionHasErrors('questions.0.answer');
    $bad = $payload;
    $bad['lessons'][0]['video_url'] = 'https://video.example/a.mp4';
    $this->put('/admin/industries/solar/training', $bad)->assertSessionHasErrors('lessons.0.transcript');

    $u = User::factory()->create();
    passTraining($this->t, $u, $this->solar);
    $this->put('/admin/industries/solar/training', [...$payload, 'retrain' => true])->assertSessionHas('status', 'training-saved-retrain');
    expect($this->t->passed($u, $this->solar))->toBeFalse();
    $this->get('/admin/industries/solar/training')->assertOk()->assertInertia(fn ($p) => $p->component('admin/industries/Training')->where('module.version', 2)->has('completion'));
});

it('gives passed members a certificate and a refresher', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/app/training/solar/certificate.pdf')->assertNotFound();
    passTraining($this->t, $u, $this->solar);
    $this->get('/app/training/solar/certificate.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get('/app/training/solar/refresher')->assertOk()->assertInertia(fn ($p) => $p->component('app/training/Refresher')->has('points', 4));
    expect(DB::table('onboarding_nudges')->where(['user_id' => $u->id, 'kind' => 'refresher'])->exists())->toBeTrue();
});

it('lets leaders remind untrained members, and shows the team badge once everyone has passed', function () {
    $leader = User::factory()->create();
    $team = Team::forLeader($leader);
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    app(Seats::class)->apply($this->group, $team, $this->city, 'L', null);

    $this->actingAs($member)->post("/app/team/members/{$leader->ulid}/remind-training")->assertForbidden();
    $this->actingAs($leader)->post("/app/team/members/{$member->ulid}/remind-training")->assertSessionHas('status', 'training-reminded');
    Notification::assertSentTo($member, InAppNotification::class, fn ($n) => $n->type === 'training.reminder');

    passTraining($this->t, $leader, $this->solar);
    expect($this->t->teamTrained($team))->toBeFalse();
    passTraining($this->t, $member, $this->solar);
    expect($this->t->teamTrained($team))->toBeTrue()
        ->and(collect(ProfileStrength::for($team)['items'])->firstWhere('label', 'Everyone on the team passes the network training')['done'])->toBeTrue();
    $this->get('/app/team')->assertInertia(fn ($p) => $p->where('teamTrained', true)->where('members.0.trained', true));
});

it('reminds members 3 and 7 days after applying, once each, until they pass', function () {
    $u = User::factory()->create();
    $done = User::factory()->create();
    app(Seats::class)->apply($this->group, Team::forLeader($u), $this->city, 'L', null);
    app(Seats::class)->apply($this->group, Team::forLeader($done), City::where('is_enabled', true)->orderBy('id')->skip(1)->firstOrFail(), 'L', null);
    passTraining($this->t, $done, $this->solar);

    $count = fn (User $x) => Notification::sent($x, InAppNotification::class)->filter(fn ($n) => $n->type === 'training.reminder')->count();
    $this->travel(3)->days();
    Artisan::call('t2t:training:reminders');
    Artisan::call('t2t:training:reminders');
    expect($count($u))->toBe(1)->and($count($done))->toBe(0);
    $this->travel(4)->days();
    Artisan::call('t2t:training:reminders');
    expect($count($u))->toBe(2);
});
