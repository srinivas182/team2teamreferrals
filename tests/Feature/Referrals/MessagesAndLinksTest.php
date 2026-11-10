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
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->b, $this->c] = [$mk('Red Rock Solar'), $mk('Hurricane Sun'), $mk('Backup Sun')];
    $s->approve($s->apply($g, $this->a, $cities[0], 'L', null), null);
    $s->approve($s->apply($g, $this->b, $cities[1], 'L', null), null);
    $s->approve($s->apply($g, $this->c, $cities[1], 'L', null), null);
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->r = app(Referrals::class)->send($this->a->leader, $g, $cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null)['referral'];
});

it('lets the two teams message, masking contact details for the receiver until they accept', function () {
    $this->actingAs($this->a->leader)->post("/app/referrals/{$this->r->ulid}/messages", ['body' => 'Tom prefers texts: (435) 555-0100, tom@example.com'])->assertRedirect();
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.message' && str_contains($n->title, 'Tom B.')
        && str_contains($n->body, '[phone shown after accepting]') && ! str_contains($n->title.$n->body, '555-0100') && ! str_contains($n->title.$n->body, 'Brennan') && ! preg_match('/\{[a-z_]+\}/', $n->title.$n->body));

    $body = fn () => $this->get("/app/referrals/{$this->r->ulid}")->inertiaProps('messages.0.body');
    $this->actingAs($this->b->leader);
    expect($body())->toContain('[phone shown after accepting]')->toContain('[email shown after accepting]');
    $this->post("/app/referrals/{$this->r->ulid}/accept");
    expect($body())->toContain('(435) 555-0100');

    $this->post("/app/referrals/{$this->r->ulid}/messages", ['body' => 'Calling him today.']);
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.message' && str_contains($n->title, 'Tom Brennan'));
    $this->actingAs(User::factory()->create())->post("/app/referrals/{$this->r->ulid}/messages", ['body' => 'Hi'])->assertNotFound();
});

it('accepts with one tap from a signed link, never on open, and never twice', function () {
    $url = Notification::sent($this->b->leader, InAppNotification::class)->first(fn ($n) => $n->type === 'referral.waiting')->url;
    expect($url)->toContain('/q/'.$this->r->ulid)->toContain('signature=');
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->get($path)->assertOk()->assertInertia(fn ($p) => $p->component('ReferralQuickAccept')->where('referral.client', 'Tom B.'));
    expect($this->r->fresh()->status)->toBe('sent');
    $this->get('/q/'.$this->r->ulid.'/'.$this->b->id)->assertForbidden();
    $this->post(str_replace('/'.$this->b->id.'?', '/'.$this->c->id.'?', $path))->assertForbidden();

    $this->post($path)->assertRedirect('/app/referrals/'.$this->r->ulid);
    expect($this->r->fresh()->status)->toBe('accepted');
    $this->post($path)->assertStatus(410);
});

it('kills an old one-tap link once the referral moves to the backup', function () {
    $url = Notification::sent($this->b->leader, InAppNotification::class)->first(fn ($n) => $n->type === 'referral.waiting')->url;
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    $this->actingAs($this->b->leader)->post("/app/referrals/{$this->r->ulid}/decline", ['reason' => 'Booked']);
    auth()->logout();
    $this->get($path)->assertInertia(fn ($p) => $p->where('referral', null));
    $this->post($path)->assertStatus(410);
});

it('gives the client a private status page that the sender can share', function () {
    $this->actingAs($this->a->leader)->get("/app/referrals/{$this->r->ulid}")->assertInertia(fn ($p) => $p->where('clientLink', url('/r/'.$this->r->client_token)));
    $this->actingAs($this->b->leader)->get("/app/referrals/{$this->r->ulid}")->assertInertia(fn ($p) => $p->where('clientLink', null));
    auth()->logout();

    $res = $this->get('/r/'.$this->r->client_token)->assertOk()->assertInertia(fn ($p) => $p->component('ClientStatus')->where('firstName', 'Tom')->where('receiver', null));
    expect((string) $res->headers->get('X-Robots-Tag'))->toContain('noindex');
    app(Referrals::class)->accept($this->r->fresh(), $this->b->leader);
    $this->get('/r/'.$this->r->client_token)->assertInertia(fn ($p) => $p->where('receiver.name', 'Hurricane Sun')->where('status', 'accepted'));
    $this->get('/r/'.str_repeat('x', 40))->assertNotFound();
});
