<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\FamilyDefaults;
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
    $this->r = app(Referrals::class)->send($this->a->leader, $this->g, $cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null)['referral'];
    $this->url = "/app/referrals/{$this->r->ulid}";
    $this->actingAs($this->b->leader)->post("{$this->url}/accept");
});

$updates = fn (User $u) => Notification::sent($u, InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.update');

it('starts accepted referrals in the first stage; one-tap call moves it on and tells the sender', function () use ($updates) {
    expect($this->r->fresh()->stage)->toBe('new');
    $this->actingAs($this->a->leader)->post("{$this->url}/call")->assertForbidden();
    $this->actingAs($this->b->leader)->post("{$this->url}/call", ['note' => 'Spoke with Tom, site survey Friday'])->assertSessionHas('status', 'call-logged');
    $r = $this->r->fresh();
    expect($r->first_contact_at)->not->toBeNull()->and($r->stage)->toBe('contacted');
    $n = $updates($this->a->leader)->last();
    expect($n->body)->toContain('Contacted')->toContain('site survey Friday')->not->toMatch('/\{[a-z_]+\}/');
    $this->get($this->url)->assertInertia(fn ($p) => $p->where('pipeline.current', 'contacted')->where('pipeline.canWork', true));
});

it('asks for the closing date and value at Won, and a reason at Lost', function () {
    $this->post("{$this->url}/stage", ['stage' => 'won'])->assertSessionHasErrors('closing_date');
    $this->post("{$this->url}/stage", ['stage' => 'won', 'closing_date' => '2026-12-20', 'closing_value' => '31500.50'])->assertSessionHas('status', 'stage-moved');
    $r = $this->r->fresh();
    expect($r->closing_value_cents)->toBe(3150050)->and($r->closing_date->toDateString())->toBe('2026-12-20')->and($r->closed_at)->not->toBeNull();

    $this->post("{$this->url}/stage", ['stage' => 'lost'])->assertSessionHasErrors('lost_reason');
    $this->post("{$this->url}/stage", ['stage' => 'nope'])->assertSessionHasErrors('stage');
});

it('posts progress notes for the sender', function () use ($updates) {
    $this->post("{$this->url}/note", ['note' => 'Waiting on HOA approval'])->assertSessionHas('status', 'update-posted');
    expect($this->r->fresh()->last_update_at)->not->toBeNull()->and($updates($this->a->leader)->last()->body)->toContain('HOA approval');
});

it('lets the sender nudge for an update, at most every three days', function () {
    $this->post("{$this->url}/nudge")->assertForbidden();
    $this->actingAs($this->a->leader)->post("{$this->url}/nudge")->assertSessionHas('status', 'nudged');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.message' && str_contains($n->title, 'would love an update'));
    $this->post("{$this->url}/nudge")->assertSessionHasErrors('nudge');
    $this->get($this->url)->assertInertia(fn ($p) => $p->where('pipeline.canNudge', false));
    $this->travel(3)->days();
    $this->post("{$this->url}/nudge")->assertSessionHas('status', 'nudged');
});

it('reminds before first contact is due, and records a miss once', function () {
    $deadline = fn () => Notification::sent($this->b->leader, InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.deadline')->count();
    $this->travel(22)->hours();
    $this->travel(30)->minutes();
    Artisan::call('t2t:referrals:expire');
    Artisan::call('t2t:referrals:expire');
    expect($deadline())->toBe(1);
    $this->travel(2)->hours();
    Artisan::call('t2t:referrals:expire');
    Artisan::call('t2t:referrals:expire');
    expect(DB::table('referral_events')->where('event', 'missed_first_contact')->count())->toBe(1);
});

it('nudges a stalled referral a day before the update is due, then records the miss', function () {
    $this->post("{$this->url}/call");
    $stalled = fn () => Notification::sent($this->b->leader, InAppNotification::class)->filter(fn ($n) => str_starts_with($n->title, 'No update on'))->count();
    $this->travel(6)->days();
    $this->travel(1)->hours();
    Artisan::call('t2t:referrals:expire');
    Artisan::call('t2t:referrals:expire');
    expect($stalled())->toBe(1);
    $this->travel(1)->days();
    Artisan::call('t2t:referrals:expire');
    expect(DB::table('referral_events')->where('event', 'missed_update')->count())->toBe(1);
});

it('nudges when a referral sits in a stage past its time limit, and shows the client a friendly stage', function () {
    $stages = FamilyDefaults::stages('home');
    $stages[1] = [...$stages[1], 'limit_days' => 2, 'client_label' => 'We’ve been in touch'];
    app(IndustryTemplates::class)->save('stages', $this->g->industry, $stages, null, $this->g->id);
    $this->post("{$this->url}/call");
    $this->travel(2)->days();
    $this->travel(1)->hours();
    Artisan::call('t2t:referrals:expire');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'for 2 days'));
    auth()->logout();
    $this->get('/r/'.$this->r->fresh()->client_token)->assertInertia(fn ($p) => $p->where('stage.label', 'We’ve been in touch'));
});
