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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $this->a = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Red Rock Solar']);
    $this->b = tap(Team::forLeader(User::factory()->create(['first_name' => 'Chris'])))->update(['name' => 'Hurricane Sun', 'phone' => '+14355550199', 'bio' => 'Family-run installers since 2009.']);
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $form = app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'];
    $this->timeline = collect($form)->firstWhere('role', 'timeline');
    $this->ans = [];
    foreach ($form as $f) {
        $this->ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->n = 0;
    $this->send = function (int $timelineOption) {
        $this->n++;

        return app(Referrals::class)->send($this->a->leader, $this->g, $this->cities[1], [...$this->ans, $this->timeline['key'] => $this->timeline['options'][$timelineOption],
            'client_name' => "Tom Brennan {$this->n}", 'client_phone' => '+143555502'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
    };
    $this->future = function () {
        $r = ($this->send)(2);
        app(Referrals::class)->accept($r->fresh(), $this->b->leader);

        return $r->fresh();
    };
});

it('sets the temperature from the timeline answer', function () {
    expect(($this->send)(0)->fresh()->temperature)->toBe('hot')->and(($this->send)(1)->fresh()->temperature)->toBe('warm')
        ->and(($this->send)(2)->fresh()->temperature)->toBe('future')->and(($this->send)(3)->fresh()->temperature)->toBe('future');
});

it('prompts the receiver monthly, and a check-in extends the protection period', function () {
    $r = ($this->future)();
    expect($r->nurture_next_at)->toBe(now()->addDays(30)->toDateString())->and($r->protection_until)->toBe(now()->addMonths(12)->toDateString());
    $this->travel(31)->days();
    Artisan::call('t2t:performance');
    Artisan::call('t2t:performance');
    $prompts = Notification::sent($this->b->leader, InAppNotification::class)->filter(fn ($n) => $n->type === 'referral.nurture');
    expect($prompts)->toHaveCount(1)->and($prompts->first()->title)->toContain('Monthly check-in');

    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/nurture", ['outcome' => 'still'])->assertForbidden();
    $next = now()->addDays(45)->toDateString();
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/nurture", ['outcome' => 'still', 'note' => 'Waiting on their tax refund', 'next_on' => $next])->assertSessionHas('status', 'nurture-still');
    $r->refresh();
    expect($r->nurture_next_at)->toBe($next)->and($r->protection_until)->toBe(now()->addMonths(12)->toDateString())
        ->and(DB::table('nurture_touchpoints')->where(['kind' => 'still', 'note' => 'Waiting on their tax refund'])->exists())->toBeTrue();
});

it('upgrades to hot when the receiver marks it ready, telling the sender; lost closes it', function () {
    $r = ($this->future)();
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/nurture", ['outcome' => 'ready'])->assertSessionHas('status', 'nurture-ready');
    expect($r->fresh()->temperature)->toBe('hot')->and($r->fresh()->nurture_next_at)->toBeNull();
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'Your Future referral is ready'));

    $r2 = ($this->future)();
    $this->post("/app/referrals/{$r2->ulid}/nurture", ['outcome' => 'lost', 'note' => 'Moved out of state']);
    expect($r2->fresh()->closed_at)->not->toBeNull()->and($r2->fresh()->lost_reason)->toBe('Moved out of state');
});

it('gives the client a portal to meet their pro and say they are ready', function () {
    $r = ($this->future)();
    $this->get('/r/'.$r->client_token)->assertOk()->assertInertia(fn ($p) => $p->component('ClientStatus')->where('receiver.name', 'Hurricane Sun')
        ->where('receiver.leader', 'Chris')->where('receiver.phone', '+14355550199')->where('canCheckIn', true)->where('temperature', 'future'));
    $this->post('/r/'.$r->client_token.'/checkin', ['answer' => 'ready'])->assertSessionHas('status', 'client-checkin');
    expect($r->fresh()->temperature)->toBe('hot');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'says they’re ready now'));

    $r2 = ($this->future)();
    $this->post('/r/'.$r2->client_token.'/checkin', ['answer' => 'not_needed']);
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'no longer need help'));
    expect($r2->fresh()->closed_at)->toBeNull(); // the pro decides
});

it('lists long-term leads for both teams, without the client’s contact details for the sender', function () {
    $r = ($this->future)();
    $this->actingAs($this->b->leader)->get('/app/nurture')->assertInertia(fn ($p) => $p->component('app/Nurture')->has('received', 1)->has('sent', 0));
    $page = $this->actingAs($this->a->leader)->get('/app/nurture');
    $page->assertInertia(fn ($p) => $p->has('sent', 1)->where('sent.0.other', 'Hurricane Sun'));
    expect($page->getContent())->not->toContain('+14355550201')->not->toContain('Tom Brennan 1');
});
