<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
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
});

function answersFor(Training $t, Industry $i, bool $allRight = true): array
{
    $qs = json_decode((string) $t->module($i)->questions, true);

    return array_map(fn ($q) => $allRight ? $q['answer'] : ($q['answer'] + 1) % count($q['options']), $qs);
}

it('writes training in each industry’s own words and rules', function () {
    $solar = json_decode((string) $this->t->module($this->solar)->lessons, true);
    $mortgage = json_decode((string) $this->t->module(Industry::where('slug', 'mortgage')->firstOrFail())->lessons, true);
    expect($solar[0]['body'])->toContain('installer')->and(implode(' ', array_column($mortgage, 'body')))->toContain('no fee, commission or other payment')->toContain('introduction');
});

it('saves lesson progress, passes a perfect quiz, and retakes only missed questions', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->post('/app/training/solar/lessons/0')->assertRedirect();
    $this->get('/app/training/solar')->assertInertia(fn ($p) => $p->component('app/training/Show')->where('done', [0])->has('questions', 5)->where('questions.0.options', fn ($o) => count($o) === 3)->missing('questions.0.answer'));

    $wrong = answersFor($this->t, $this->solar);
    $wrong[1] = ($wrong[1] + 1) % 3;
    $wrong[2] = ($wrong[2] + 1) % 3;
    $this->post('/app/training/solar/quiz', ['answers' => $wrong])->assertSessionHas('trainingResult', fn ($r) => $r['score'] === 60 && ! $r['passed'] && count($r['wrong']) === 2 && $r['wrong'][0]['why'] !== '');
    $this->get('/app/training/solar')->assertInertia(fn ($p) => $p->where('retake', true)->has('questions', 2));

    $right = answersFor($this->t, $this->solar);
    $this->post('/app/training/solar/quiz', ['answers' => [1 => $right[1], 2 => $right[2]]])->assertSessionHas('trainingResult', fn ($r) => $r['score'] === 100 && $r['passed']);
    expect($this->t->passed($u, $this->solar))->toBeTrue();
});

it('gates new referrals only after the grace period, and the backup receives meanwhile', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $s = app(Seats::class);
    $city = City::where('is_enabled', true)->orderBy('id')->firstOrFail();
    $holder = Team::forLeader(User::factory()->create());
    $backup = Team::forLeader(User::factory()->create());
    $s->approve($s->apply($g, $holder, $city, 'L', null), null);
    $s->approve($s->apply($g, $backup, $city, 'L', null), null);
    $seat = Seat::firstOrFail();

    expect($this->t->blocks($holder->leader, $g))->toBeFalse()->and($this->t->receiver($seat)?->id)->toBe($holder->id);
    $this->travel(15)->days();
    expect($this->t->blocks($holder->leader, $g))->toBeTrue()->and($this->t->receiver($seat->fresh())?->id)->toBeNull();

    $this->t->submit($backup->leader, $this->t->module($this->solar), answersFor($this->t, $this->solar));
    expect($this->t->receiver($seat->fresh())?->id)->toBe($backup->id);
    $this->t->submit($holder->leader, $this->t->module($this->solar), answersFor($this->t, $this->solar));
    expect($this->t->receiver($seat->fresh())?->id)->toBe($holder->id);
});

it('never blocks when the owner makes training optional, and turning it on starts a new grace period with notices', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $owner = User::factory()->create();
    $g->owners()->attach($owner->id);
    $holder = Team::forLeader(User::factory()->create());
    $s = app(Seats::class);
    $s->approve($s->apply($g, $holder, City::where('is_enabled', true)->orderBy('id')->firstOrFail(), 'L', null), null);

    $this->actingAs($owner)->post('/app/group/solar-installers-network/training', ['required' => false])->assertSessionHas('status', 'training-optional');
    $this->travel(30)->days();
    expect($this->t->blocks($holder->leader, $g->fresh()))->toBeFalse();

    $this->post('/app/group/solar-installers-network/training', ['required' => true])->assertSessionHas('status', 'training-required');
    expect($this->t->blocks($holder->leader, $g->fresh()))->toBeFalse();
    Notification::assertSentTo($holder->leader, InAppNotification::class, fn ($n) => $n->type === 'network.announcement');
    $this->travel(15)->days();
    expect($this->t->blocks($holder->leader, $g->fresh()))->toBeTrue();
});

it('keeps training on where the platform requires it, and only owners can switch', function () {
    $g = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $owner = User::factory()->create();
    $g->owners()->attach($owner->id);
    $this->actingAs($owner)->post('/app/group/mortgage-lenders-circle/training', ['required' => false])->assertSessionHasErrors('required');
    expect(Training::required($g->fresh()))->toBeTrue();
    $this->actingAs(User::factory()->create())->post('/app/group/solar-installers-network/training', ['required' => false])->assertForbidden();
});

it('asks members to re-train after a material change', function () {
    $u = User::factory()->create();
    $this->t->submit($u, $this->t->module($this->solar), answersFor($this->t, $this->solar));
    expect($this->t->passed($u, $this->solar))->toBeTrue();
    DB::table('training_modules')->where('industry_id', $this->solar->id)->update(['version' => 2, 'min_version' => 2]);
    expect($this->t->passed($u, $this->solar))->toBeFalse();
});
