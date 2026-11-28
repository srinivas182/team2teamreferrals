<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\SharingReward;
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
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    $this->a = $mk('Sender Solar');
    $this->b = $mk('Holder Solar');
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    // A sends to B's city; B accepts.
    $this->n = 0;
    $this->sendAccepted = function (): Referral {
        $n = ++$this->n; // a different client each time, so duplicate checks don't stop the send
        $form = app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'];
        $answers = [];
        foreach ($form as $f) {
            $answers[$f['key']] = match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text',
            };
        }
        $this->actingAs($this->a->leader)->post('/app/send', ['group' => 'solar-installers-network', 'city_id' => $this->cities[1]->id, 'consent' => true,
            'answers' => [...$answers, 'client_name' => "Tom Brennan {$n}", 'client_phone' => '+1435555010'.$n, 'client_email' => "tom{$n}@example.com"]])->assertSessionHasNoErrors();
        $r = Referral::query()->latest('id')->firstOrFail();
        $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/accept")->assertSessionHas('status', 'referral-accepted');

        return $r->fresh();
    };
    $this->mark = fn (Referral $r) => $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/not-real", ['reason' => 'wrong_contact', 'note' => 'Called three times, the number belongs to someone else.']);
});

it('lets the receiver mark an accepted client not real, closes it, and tells the sender', function () {
    $r = ($this->sendAccepted)();
    $this->actingAs($this->b->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('canMarkNotReal', true)->has('notRealReasons'));
    $this->post("/app/referrals/{$r->ulid}/not-real", ['reason' => 'wrong_contact', 'note' => 'short'])->assertSessionHasErrors('note');
    ($this->mark)($r)->assertSessionHas('status', 'not-real-marked');
    $r->refresh();
    expect($r->invalid_at)->not->toBeNull()->and($r->stage)->toBe('lost')->and($r->closed_at)->not->toBeNull()
        ->and(DB::table('referral_events')->where(['referral_id' => $r->id, 'event' => 'marked_not_real'])->exists())->toBeTrue();
    $this->actingAs($this->a->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('notReal.disputed', false)->where('canMarkNotReal', false));
});

it('stops the sender, an unaccepted referral and a second mark from being marked not real', function () {
    $r = ($this->sendAccepted)();
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/not-real", ['reason' => 'spam', 'note' => 'Trying to mark my own referral.'])->assertForbidden();
    ($this->mark)($r);
    ($this->mark)($r)->assertForbidden();
    $late = ($this->sendAccepted)();
    $late->forceFill(['accepted_at' => now()->subDays(31)])->save();
    ($this->mark)($late)->assertForbidden(); // more than 30 days after accepting
});

it('lets the sender ask the owner to review once, and the owner reinstate or uphold', function () {
    $r = ($this->sendAccepted)();
    ($this->mark)($r);
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/not-real/dispute", ['why' => 'The client confirmed by email that he wants solar.'])->assertSessionHas('status', 'not-real-disputed');
    $this->actingAs($this->owner)->get('/app/group/solar-installers-network/referrals')->assertInertia(fn ($p) => $p->has('notRealReviews', 1)->where('notRealReviews.0.sender', 'Sender Solar'));
    $this->actingAs($this->b->leader)->post("/app/group/solar-installers-network/not-real/{$r->ulid}", ['decision' => 'reinstate'])->assertForbidden();
    $this->actingAs($this->owner)->post("/app/group/solar-installers-network/not-real/{$r->ulid}", ['decision' => 'uphold'])->assertSessionHas('status', 'not-real-decided');
    expect($r->fresh()->invalid_at)->not->toBeNull()->and($r->fresh()->invalid_disputed_at)->toBeNull();
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/not-real/dispute", ['why' => 'Please look again at this one.'])->assertStatus(422);

    $r2 = ($this->sendAccepted)();
    ($this->mark)($r2);
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r2->ulid}/not-real/dispute", ['why' => 'The client is real and still waiting.']);
    $this->actingAs($this->owner)->post("/app/group/solar-installers-network/not-real/{$r2->ulid}", ['decision' => 'reinstate']);
    expect($r2->fresh()->invalid_at)->toBeNull();
});

it('pauses the sender’s sharing reward after 3 not-real clients and alerts the group owners', function () {
    foreach (range(1, 3) as $i) {
        ($this->mark)(($this->sendAccepted)());
    }
    expect(SharingReward::paused($this->a->id))->toBeTrue();
    Notification::assertSentTo($this->owner, App\Domains\Messaging\InAppNotification::class);
    $this->actingAs($this->a->leader)->get('/app')->assertInertia(fn ($p) => $p->where('sharing.0.paused', true));
});

it('shows the sharing reward progress on the dashboard', function () {
    $this->actingAs($this->a->leader)->get('/app')->assertInertia(fn ($p) => $p->where('sharing.0.group', 'Solar Installers Network')->where('sharing.0.nextFree', false));
    $r = ($this->sendAccepted)();
    $r->forceFill(['first_contact_at' => now()])->save();
    $this->actingAs($this->a->leader)->get('/app')->assertInertia(fn ($p) => $p->where('sharing.0.sent', 1)->where('sharing.0.nextFree', true));
});
