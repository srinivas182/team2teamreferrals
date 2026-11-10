<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Referrals;
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->b] = [$mk('Red Rock Solar'), $mk('Hurricane Sun')];
    $s->approve($s->apply($this->g, $this->a, $cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $cities[1], 'L', null), null);
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $R = app(Referrals::class);
    $this->refs = collect(['Tom Brennan', 'Ana Ruiz'])->map(fn ($n, $i) => $R->send($this->a->leader, $this->g, $cities[1], [...$ans, 'client_name' => $n, 'client_phone' => '+1435555010'.$i], true, null, true)['referral']);
    foreach ($this->refs as $r) {
        $R->accept($r->fresh(), $this->b->leader);
    }
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('super_admin');
});

it('shows the receiving team its referrals by stage, with what needs attention', function () {
    $this->actingAs($this->b->leader)->get('/app/pipeline')->assertOk()->assertInertia(fn ($p) => $p->component('app/Pipeline')
        ->where('group', 'solar-installers-network')->has('cards', 2)->where('cards.0.stage', 'new')->where('cards.0.flags.0', 'Call due')->where('stages.0.key', 'new'));
    $this->actingAs($this->a->leader)->get('/app/pipeline')->assertInertia(fn ($p) => $p->has('cards', 0));
    $this->travel(25)->hours();
    $this->actingAs($this->b->leader)->get('/app/pipeline')->assertInertia(fn ($p) => $p->where('cards.0.flags.0', 'First contact overdue'));
});

it('lets owners rename, add and limit stages, keeping the pipeline rules', function () {
    $stages = app(IndustryTemplates::class)->resolve('stages', $this->g->industry, $this->g->id)['body'];
    $stages[1]['label'] = 'First call made';
    $stages[1]['client_label'] = 'We’ve been in touch';
    $stages[1]['limit_days'] = 3;
    array_splice($stages, 2, 0, [['key' => 'permit', 'label' => 'Permit approved', 'kind' => 'open']]);

    $this->actingAs($this->owner)->put('/app/group/solar-installers-network/pipeline', ['stages' => array_values(array_filter($stages, fn ($s) => $s['kind'] !== 'won'))])->assertSessionHasErrors('stages');
    $this->put('/app/group/solar-installers-network/pipeline', ['stages' => $stages])->assertSessionHas('status', 'pipeline-saved');
    $saved = app(IndustryTemplates::class)->resolve('stages', $this->g->industry, $this->g->id);
    expect($saved['source'])->toBe('group')->and($saved['body'][1]['label'])->toBe('First call made')->and($saved['body'][1]['limit_days'])->toBe(3)->and($saved['body'][2]['key'])->toBe('permit');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'network.announcement' && str_contains($n->body, 'Permit approved'));
    $this->actingAs(User::factory()->create())->get('/app/group/solar-installers-network/pipeline')->assertForbidden();
});

it('only removes a stage with referrals once the owner chooses where they go', function () {
    $stages = array_values(array_filter(app(IndustryTemplates::class)->resolve('stages', $this->g->industry, $this->g->id)['body'], fn ($s) => $s['key'] !== 'new'));
    $this->actingAs($this->owner)->put('/app/group/solar-installers-network/pipeline', ['stages' => $stages])->assertSessionHasErrors('moves');
    expect($this->refs[0]->fresh()->stage)->toBe('new');

    $this->put('/app/group/solar-installers-network/pipeline', ['stages' => $stages, 'moves' => ['new' => 'contacted']])->assertSessionHas('status', 'pipeline-saved');
    expect($this->refs->map(fn ($r) => $r->fresh()->stage)->unique()->all())->toBe(['contacted'])
        ->and(DB::table('referral_events')->where('event', 'stage')->where('meta', 'like', '%pipeline changed%')->count())->toBe(2);
});

it('lets admins lock a pipeline, which owners then can’t change', function () {
    $stages = app(IndustryTemplates::class)->resolve('stages', $this->g->industry, $this->g->id)['body'];
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/pipeline/lock', ['locked' => true])->assertForbidden();
    $this->actingAs($this->admin)->post('/app/group/solar-installers-network/pipeline/lock', ['locked' => true])->assertSessionHas('status', 'pipeline-locked');
    $this->actingAs($this->owner)->put('/app/group/solar-installers-network/pipeline', ['stages' => $stages])->assertSessionHasErrors('stages');
    $this->actingAs($this->admin)->put('/app/group/solar-installers-network/pipeline', ['stages' => $stages])->assertSessionHas('status', 'pipeline-saved');
});
