<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Referral;
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
    $this->c = $mk('Backup Solar');
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $s->approve($s->apply($this->g, $this->c, $this->cities[1], 'L', null), null);
});

function answers(Group $g, array $over = []): array
{
    $form = app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'];
    $a = [];
    foreach ($form as $f) {
        $a[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text',
        };
    }

    return [...$a, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100', 'client_email' => 'tom@example.com', ...$over];
}

function sendAs($test, Team $t, array $over = [])
{
    return $test->actingAs($t->leader)->post('/app/send', ['group' => 'solar-installers-network', 'city_id' => $test->cities[1]->id, 'answers' => answers($test->g), 'consent' => true, ...$over]);
}

it('previews who will receive a referral before sending', function () {
    $this->actingAs($this->a->leader)->getJson("/app/send/preview?group=solar-installers-network&city_id={$this->cities[1]->id}")
        ->assertOk()->assertJson(['team' => 'Holder Solar', 'role' => 'holder', 'backup' => 'Backup Solar']);
});

it('sends with recorded consent to the holder, and tells them', function () {
    sendAs($this, $this->a, ['consent' => false])->assertSessionHasErrors('consent');
    sendAs($this, $this->a, ['answers' => [...answers($this->g), 'client_name' => '']])->assertSessionHasErrors('answers.client_name');
    $outsider = Team::forLeader(User::factory()->create());
    sendAs($this, $outsider)->assertSessionHasErrors('group');

    sendAs($this, $this->a)->assertSessionHas('status', 'referral-sent');
    $r = Referral::firstOrFail();
    expect($r->receiver_team_id)->toBe($this->b->id)->and($r->routed_to)->toBe('holder')->and($r->consent_method)->toBe('sender_attested')
        ->and($r->consent_text)->toContain('homeowner')->and($r->respond_by->diffInHours(now(), true))->toBeGreaterThan(23.9);
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.waiting' && str_contains($n->title, 'Tom B.') && ! str_contains($n->title.$n->body, 'Brennan'));
});

it('hides the client’s contact details from the receiver until they accept, and keeps outsiders out', function () {
    sendAs($this, $this->a);
    $r = Referral::firstOrFail();
    $phone = fn ($p) => collect($p->toArray()['props']['referral']['answers'])->firstWhere('label', 'Client mobile')['value'];
    $this->actingAs($this->b->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => tap($p, fn () => expect($phone($p))->toBeNull())->where('referral.client', 'Tom B.'));
    $this->get('/app/inbox')->assertInertia(fn ($p) => $p->where('items.0.client', 'Tom B.'));
    $this->actingAs($this->a->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => tap($p, fn () => expect($phone($p))->toBe('+14355550100')));
    $this->actingAs(User::factory()->create())->get("/app/referrals/{$r->ulid}")->assertNotFound();

    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/accept")->assertSessionHas('status', 'referral-accepted');
    $this->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => tap($p, fn () => expect($phone($p))->toBe('+14355550100'))->where('referral.client', 'Tom Brennan'));
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.accepted');
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/accept")->assertForbidden();
});

it('passes a declined referral to the backup, then closes it if the backup declines too', function () {
    sendAs($this, $this->a);
    $r = Referral::firstOrFail();
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/decline", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/app/referrals/{$r->ulid}/decline", ['reason' => 'Fully booked this month'])->assertRedirect('/app/inbox');
    expect($r->fresh()->receiver_team_id)->toBe($this->c->id)->and($r->fresh()->status)->toBe('sent')->and($r->fresh()->routed_to)->toBe('backup');
    Notification::assertSentTo($this->c->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.waiting');
    $this->get("/app/referrals/{$r->ulid}")->assertNotFound();

    $this->actingAs($this->c->leader)->post("/app/referrals/{$r->ulid}/decline", ['reason' => 'Out of area']);
    expect($r->fresh()->status)->toBe('declined');
    expect(DB::table('referral_events')->where('referral_id', $r->id)->pluck('event')->all())->toBe(['sent', 'declined', 'passed_to_backup', 'declined']);
});

it('moves a referral to the backup when the deadline passes', function () {
    sendAs($this, $this->a);
    $this->travel(25)->hours();
    Artisan::call('t2t:referrals:expire');
    $r = Referral::firstOrFail();
    expect($r->receiver_team_id)->toBe($this->c->id)->and(DB::table('referral_events')->where(['referral_id' => $r->id, 'event' => 'expired'])->exists())->toBeTrue();
});

it('warns about a client referred recently, and sends when confirmed', function () {
    sendAs($this, $this->a);
    sendAs($this, $this->a)->assertSessionHas('duplicate', true);
    expect(Referral::count())->toBe(1);
    sendAs($this, $this->a, ['confirm_duplicate' => true])->assertSessionHas('status', 'referral-sent');
    expect(Referral::count())->toBe(2);
});

it('applies the training gate to senders and routes around untrained holders', function () {
    $this->travel(15)->days();
    sendAs($this, $this->a)->assertSessionHasErrors('group');
    $t = app(App\Domains\Network\Training\Training::class);
    $pass = fn (User $u) => $t->submit($u, $t->module($this->g->industry), array_column(json_decode((string) $t->module($this->g->industry)->questions, true), 'answer'));
    $pass($this->a->leader);
    $pass($this->c->leader);
    sendAs($this, $this->a)->assertSessionHas('status', 'referral-sent');
    expect(Referral::firstOrFail()->receiver_team_id)->toBe($this->c->id);
});

it('refuses sending to your own territory, and lists inbox and sent', function () {
    sendAs($this, $this->a, ['city_id' => $this->cities[0]->id])->assertSessionHasErrors('city_id');
    sendAs($this, $this->a);
    $this->actingAs($this->b->leader)->get('/app/inbox')->assertInertia(fn ($p) => $p->component('app/referrals/Inbox')->has('items', 1));
    $this->actingAs($this->a->leader)->get('/app/sent')->assertInertia(fn ($p) => $p->component('app/referrals/Sent')->has('items', 1)->where('items.0.receiver', 'Holder Solar'));
});
