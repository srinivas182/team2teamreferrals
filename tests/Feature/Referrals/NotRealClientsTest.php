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

it('warns politely at 2, pauses at 3 with an end date, and tells the owners without blame', function () {
    $sent = fn (User $u, string $needle) => Notification::assertSentTo($u, App\Domains\Messaging\InAppNotification::class, fn ($n) => str_contains($n->title.' '.$n->body, $needle));
    ($this->mark)(($this->sendAccepted)());
    ($this->mark)(($this->sendAccepted)());
    $sent($this->a->leader, 'A quick check before you send your next referral');
    expect(SharingReward::paused($this->a->id, $this->g->id))->toBeFalse();
    ($this->mark)(($this->sendAccepted)());
    expect(SharingReward::paused($this->a->id, $this->g->id))->toBeTrue()
        ->and(SharingReward::pausedUntil($this->a->id, $this->g->id)?->toDateString())->toBe(now()->addDays(91)->toDateString())
        ->and(DB::table('sharing_reward_pauses')->count())->toBe(1);
    $sent($this->a->leader, 'Your seat and the referrals you receive are not affected');
    $sent($this->owner, 'No action is needed');
    Notification::assertNotSentTo($this->owner, App\Domains\Messaging\InAppNotification::class, fn ($n) => str_contains($n->title.' '.$n->body, 'keeps sending'));
    $this->actingAs($this->a->leader)->get('/app')->assertInertia(fn ($p) => $p->where('sharing.0.paused', true)->where('sharing.0.notReal', 3)->whereNot('sharing.0.pausedUntil', null));
    $this->actingAs($this->a->leader)->get('/app/performance')->assertInertia(fn ($p) => $p->where('sharing.0.paused', true));
    $this->actingAs($this->owner)->get('/app/group/solar-installers-network/referrals')->assertInertia(fn ($p) => $p->has('pausedTeams', 1)->where('pausedTeams.0.team', 'Sender Solar'));
});

it('counts per group: a pause in one group leaves the reward in others alone', function () {
    foreach (range(1, 3) as $i) {
        ($this->mark)(($this->sendAccepted)());
    }
    $other = Group::where('slug', '!=', 'solar-installers-network')->firstOrFail();
    expect(SharingReward::paused($this->a->id, $this->g->id))->toBeTrue()->and(SharingReward::paused($this->a->id, $other->id))->toBeFalse();
});

it('lets the owner lift a pause with a reason (any number of times), keep it, or remove the team', function () {
    foreach (range(1, 3) as $i) {
        ($this->mark)(($this->sendAccepted)());
    }
    $url = "/app/group/solar-installers-network/sharing-pause/{$this->a->id}";
    $this->actingAs($this->b->leader)->post($url, ['action' => 'lift', 'reason' => 'Looks fine'])->assertForbidden();
    $this->actingAs($this->owner)->post($url, ['action' => 'lift'])->assertSessionHasErrors('reason');
    $this->post($url, ['action' => 'lift', 'reason' => 'Bad phone numbers from a lead list, now fixed'])->assertSessionHas('status', 'sharing-pause-lift');
    expect(SharingReward::paused($this->a->id, $this->g->id))->toBeFalse()->and(SharingReward::invalidSent($this->a->id, $this->g->id))->toBe(0);
    Notification::assertSentTo($this->a->leader, App\Domains\Messaging\InAppNotification::class, fn ($n) => str_contains($n->title, 'is back on'));

    // Paused again later: the owner is now asked to review, and can keep it or lift it again.
    $this->travel(1)->minutes();
    foreach (range(1, 3) as $i) {
        ($this->mark)(($this->sendAccepted)());
    }
    Notification::assertSentTo($this->owner, App\Domains\Messaging\InAppNotification::class, fn ($n) => str_contains($n->title, 'Please review Sender Solar'));
    $this->actingAs($this->owner)->post($url, ['action' => 'keep'])->assertSessionHas('status', 'sharing-pause-keep');
    expect(SharingReward::paused($this->a->id, $this->g->id))->toBeTrue();
    $this->post($url, ['action' => 'lift', 'reason' => 'Second chance after a call'])->assertSessionHas('status', 'sharing-pause-lift');
    expect(SharingReward::paused($this->a->id, $this->g->id))->toBeFalse();

    // A third pause within the year also tells the super admins.
    $super = User::factory()->create();
    $super->assignRole('super_admin');
    $this->travel(1)->minutes();
    foreach (range(1, 3) as $i) {
        ($this->mark)(($this->sendAccepted)());
    }
    Notification::assertSentTo($super, App\Domains\Messaging\InAppNotification::class, fn ($n) => str_contains($n->title, 'Please review Sender Solar'));

    $this->actingAs($this->owner)->post($url, ['action' => 'remove', 'reason' => 'Repeated fake referrals'])->assertSessionHas('status', 'sharing-pause-remove');
    expect(App\Domains\Network\Seats\Models\Seat::where(['group_id' => $this->g->id, 'primary_team_id' => $this->a->id])->exists())->toBeFalse();
});

it('puts referral quality in the trust score with counts, and shows the track record on the profile', function () {
    foreach (range(1, 4) as $i) {
        $r = ($this->sendAccepted)();
        if ($i === 1) {
            ($this->mark)($r);
        }
    }
    $t = App\Domains\Referrals\Trust\TrustScore::compute($this->a);
    expect($t['parts']['quality'])->toMatchArray(['items' => 4, 'good' => 3, 'label' => 'Referral quality'])->and($t['parts']['quality']['points'])->toBe(75);
    App\Domains\Referrals\Trust\TrustScore::recomputeAll();
    $profile = (string) DB::table('teams')->where('id', $this->a->id)->value('slug');
    $page = $this->get("/t/{$profile}");
    $page->assertOk()->assertInertia(fn ($p) => $p->where('record.trust.parts.quality', ['items' => 4, 'good' => 3]));
});

it('shows the sharing reward progress on the dashboard', function () {
    $this->actingAs($this->a->leader)->get('/app')->assertInertia(fn ($p) => $p->where('sharing.0.group', 'Solar Installers Network')->where('sharing.0.nextFree', false));
    $r = ($this->sendAccepted)();
    $r->forceFill(['first_contact_at' => now()])->save();
    $this->actingAs($this->a->leader)->get('/app')->assertInertia(fn ($p) => $p->where('sharing.0.sent', 1)->where('sharing.0.nextFree', true));
});
