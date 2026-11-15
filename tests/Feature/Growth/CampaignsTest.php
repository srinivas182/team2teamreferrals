<?php

use App\Domains\Growth\Attribution;
use App\Domains\Growth\Campaign;
use App\Domains\Growth\Campaigns;
use App\Domains\Growth\CampaignStep;
use App\Domains\Growth\GrowthMessage;
use App\Domains\Growth\Prospect;
use App\Domains\Growth\Prospects;
use App\Domains\Growth\Tracking;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

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
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->cities = City::where('is_enabled', true)->orderByDesc('population')->get();
    $this->staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now(), 'first_name' => 'Sam', 'email' => 'sam@t2t.example']);
    $this->staff->assignRole(Permissions::SUPER_ADMIN);
    $this->on = function (): void {
        app(Settings::class)->set('general.postal_address', '1 Main St, Austin, TX 78701');
        app(Settings::class)->set('growth.sending', '1');
    };
    // The ready-made sequence, open all day every day so the clock in the tests doesn't matter.
    $this->finish = tap(Campaign::where('kind', 'abandoned')->firstOrFail())->update(['window_start' => 0, 'window_end' => 24, 'weekdays_only' => false]);
    $this->sent = function (): array {
        $out = [];
        Notification::assertSentOnDemand(GrowthMessage::class, function ($n, $ch, $to) use (&$out) {
            $out[] = ['to' => $to->routes['mail'], 'mail' => $n->toMail($to)];

            return true;
        });

        return $out;
    };
});

it('follows up an abandoned sign-up and tracks them through to joining (the 46 "done when")', function () {
    ($this->on)();
    $first = json_encode(['source' => 'google', 'medium' => 'cpc', 'campaign' => 'solar-austin', 'landing' => '/industries/solar', 'referrer' => 'www.google.com', 'at' => now()->subDay()->toIso8601String()]);
    $this->withCookie(Attribution::FIRST, $first)->post('/register', ['first_name' => 'Jordan', 'last_name' => 'Hale', 'email' => 'jordan@example.com', 'phone' => '(435) 555-0119',
        'password' => 'a long enough password', 'password_confirmation' => 'a long enough password', 'terms' => true])->assertRedirect('/app');
    $user = User::where('email', 'jordan@example.com')->firstOrFail();
    $p = Prospect::where('user_id', $user->id)->firstOrFail();
    expect(DB::table('campaign_enrollments')->where(['campaign_id' => $this->finish->id, 'prospect_id' => $p->id])->value('status'))->toBe('active');
    expect(DB::table('signup_attributions')->where('user_id', $user->id)->first())->first_source->toBe('google')->first_medium->toBe('cpc')->first_campaign->toBe('solar-austin');

    expect(Campaigns::run())->toBe(0); // the first email waits an hour
    $this->travel(61)->minutes();
    expect(Campaigns::run())->toBe(1);
    [$one] = ($this->sent)();
    expect($one['to'])->toBe('jordan@example.com')->and($one['mail']->subject)->toContain('Jordan');
    $send = DB::table('campaign_sends')->first();
    expect($one['mail']->actionUrl)->toStartWith(url('/g/c/'.$send->ulid))->and(implode(' ', $one['mail']->outroLines))->toContain('/g/o/'.$send->ulid.'.gif');

    // The click is recorded and remembered, then they come back and apply for a seat.
    $target = parse_url($one['mail']->actionUrl);
    parse_str((string) $target['query'], $q);
    expect($q['u'])->toContain('utm_campaign=finish-joining-abandoned-sign-up');
    $this->get('/g/c/'.$send->ulid.'?'.http_build_query(['u' => $q['u'], 's' => $q['s']]))->assertRedirect($q['u'])->assertCookie(Attribution::CAMPAIGN, (string) $this->finish->id);
    $this->get('/g/c/'.$send->ulid.'?'.http_build_query(['u' => 'https://evil.example', 's' => $q['s']]))->assertNotFound();
    expect(DB::table('campaign_sends')->value('clicked_at'))->not->toBeNull()->and($p->fresh()->score)->toBeGreaterThan(12);

    $this->travel(24)->hours();
    expect(Campaigns::run())->toBe(1); // email 2
    $seats = app(Seats::class);
    $team = Team::forLeader($user);
    $seats->approve($seats->apply($this->g, $team, $this->cities[0], 'L', null), null);
    $this->travel(5)->days();
    expect(Campaigns::run())->toBe(0); // reached the goal: no third email
    expect(DB::table('campaign_enrollments')->where('prospect_id', $p->id)->first())->status->toBe('converted')->exit_reason->toBe('applied');
    expect($p->fresh()->stage)->toBe('joined');

    // Reports: the campaign's results and where the sign-up came from.
    $this->flushSession(); // the test browser is still signed in as Jordan
    expect(Campaigns::stats($this->finish->fresh()))->toMatchArray(['enrolled' => 1, 'converted' => 1, 'sent' => 2, 'clicked' => 1]);
    $this->actingAs($this->staff)->get('/admin/growth/sources?days=30')->assertOk()->assertInertia(fn ($pg) => $pg->component('growth/Sources')
        ->where('rows.0', ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'solar-austin', 'signups' => 1, 'joined' => 1]));
    $this->actingAs($this->staff)->get('/admin/growth/campaigns')->assertInertia(fn ($pg) => $pg->component('growth/Campaigns')->where('campaigns.0.stats.converted', 1));
});

it('sends nothing while prospect email is off: people just wait', function () {
    $user = User::factory()->create();
    Prospects::onSignup($user);
    Campaigns::onSignup($user);
    $this->travel(3)->hours();
    expect(Campaigns::run())->toBe(0)->and(DB::table('campaign_sends')->count())->toBe(0);
    ($this->on)();
    expect(Campaigns::run())->toBe(1);
});

it('runs a drip campaign: audience, time zone window, weekdays, daily cap and A/B subjects', function () {
    ($this->on)();
    $c = Campaign::create(['name' => 'Texas solar', 'status' => 'active', 'audience' => ['state' => 'TX'], 'window_start' => 9, 'window_end' => 17, 'weekdays_only' => true, 'daily_cap' => 2]);
    $step = CampaignStep::create(['campaign_id' => $c->id, 'position' => 1, 'wait_hours' => 0, 'subject' => 'A: seats in {city}', 'subject_b' => 'B: your {city} seat', 'body' => 'Hi {first_name}', 'cta_label' => 'See', 'cta_url' => '/groups']);
    $austin = City::query()->where('name', 'Austin')->first() ?? $this->cities[0];
    foreach (['a', 'b', 'c'] as $n) {
        Prospects::upsert(['email' => "{$n}@example.com", 'first_name' => strtoupper($n), 'city_id' => $austin->id, 'state_code' => 'TX'], 'manual', 'added_by_staff');
    }
    Prospects::upsert(['email' => 'ca@example.com', 'state_code' => 'CA'], 'manual', 'added_by_staff');
    // Tuesday 07:00 in Central time: matching people are added, but it's before their window.
    $this->travelTo(now()->parse('2027-03-02 07:00', 'America/Chicago'));
    expect(Campaigns::run())->toBe(0);
    expect(DB::table('campaign_enrollments')->where('campaign_id', $c->id)->count())->toBe(3);
    $this->travelTo(now()->parse('2027-03-02 10:00', 'America/Chicago'));
    expect(Campaigns::run())->toBe(2); // the daily cap
    expect(DB::table('campaign_sends')->pluck('variant')->sort()->values()->all())->toBe(['a', 'b']);
    $this->travelTo(now()->parse('2027-03-03 10:00', 'America/Chicago'));
    expect(Campaigns::run())->toBe(1);
    expect(DB::table('campaign_enrollments')->where('campaign_id', $c->id)->where('status', 'completed')->count())->toBe(3);

    // A/B: with 50 sends a side at least two days old, the subject with more clicks wins.
    $p = Prospect::first();
    foreach (range(1, 100) as $i) {
        DB::table('campaign_sends')->insert(['ulid' => strtolower((string) Str::ulid()), 'campaign_id' => $c->id, 'campaign_step_id' => $step->id, 'prospect_id' => $p->id, 'variant' => $i % 2 ? 'a' : 'b',
            'subject' => 'x', 'status' => 'sent', 'sent_at' => now()->subDays(3), 'clicked_at' => ($i % 2 === 0 && $i % 4 === 0) || $i % 10 === 1 ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }
    Campaigns::run();
    expect($step->fresh()->winner)->toBe('b');
});

it('stops for replies, bounces, complaints and unsubscribes', function () {
    ($this->on)();
    config(['mail.inbound.domain' => 'in.t2t.example', 'mail.inbound.secret' => 'shh']);
    $owner = User::factory()->create();
    $owner->givePermissionTo('growth.manage');
    $c = Campaign::create(['name' => 'Drip', 'status' => 'active', 'window_start' => 0, 'window_end' => 24, 'weekdays_only' => false]);
    CampaignStep::create(['campaign_id' => $c->id, 'position' => 1, 'wait_hours' => 0, 'subject' => 'One', 'body' => 'Hi', 'cta_label' => null, 'cta_url' => null]);
    CampaignStep::create(['campaign_id' => $c->id, 'position' => 2, 'wait_hours' => 24, 'subject' => 'Two', 'body' => 'Hi again', 'cta_label' => null, 'cta_url' => null]);
    $people = collect(['reply', 'bounce', 'spam', 'unsub'])->mapWithKeys(fn ($n) => [$n => tap(Prospects::upsert(['email' => "{$n}@example.com"], 'manual', 'added_by_staff'), fn ($p) => $p->forceFill(['owner_id' => $owner->id])->save())]);
    foreach ($people as $p) {
        Campaigns::enroll($c, $p);
    }
    expect(Campaigns::run())->toBe(4);
    $mail = collect(($this->sent)())->firstWhere('to', 'reply@example.com')['mail'];
    $send = DB::table('campaign_sends')->where('prospect_id', $people['reply']->id)->first();
    expect($mail->replyTo)->toBe([['reply+g'.$send->ulid.'@in.t2t.example', null]]);

    $this->withHeader('X-Inbound-Secret', 'shh')->postJson('/webhooks/inbound-email', ['OriginalRecipient' => 'reply+g'.$send->ulid.'@in.t2t.example', 'FromFull' => ['Email' => 'reply@example.com'],
        'StrippedTextReply' => 'Yes, tell me more about Austin', 'MessageID' => 'm1'])->assertOk()->assertJson(['status' => 'growth-reply']);
    Notification::assertSentTo($owner, InAppNotification::class, fn ($n) => $n->type === 'growth.tasks' && str_contains($n->body, 'tell me more'));
    $this->withHeader('X-Inbound-Secret', 'shh')->postJson('/webhooks/email-events', ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'bounce@example.com'])->assertJson(['status' => 'bounce']);
    $this->withHeader('X-Inbound-Secret', 'shh')->postJson('/webhooks/email-events', ['RecordType' => 'SpamComplaint', 'Email' => 'spam@example.com'])->assertJson(['status' => 'complaint']);
    $this->withHeader('X-Inbound-Secret', 'shh')->postJson('/webhooks/email-events', ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'unsub@example.com'])->assertJson(['status' => 'ignored']);
    $this->withHeader('X-Inbound-Secret', 'wrong')->postJson('/webhooks/email-events', ['type' => 'bounce', 'email' => 'unsub@example.com'])->assertUnauthorized();
    $this->post(App\Domains\Growth\GrowthMailer::unsubscribeUrl($people['unsub']))->assertOk();
    $reasons = DB::table('campaign_enrollments')->where('campaign_id', $c->id)->pluck('exit_reason', 'prospect_id');
    expect($reasons[$people['reply']->id])->toBe('replied')->and($reasons[$people['bounce']->id])->toBe('bounced')->and($reasons[$people['spam']->id])->toBe('complained')->and($reasons[$people['unsub']->id])->toBe('unsubscribed');
    expect(Prospects::suppressed('bounce@example.com'))->toBeTrue()->and(Prospects::suppressed('spam@example.com'))->toBeTrue();
    $this->travel(2)->days();
    expect(Campaigns::run())->toBe(0);
    expect(Campaigns::stats($c))->toMatchArray(['sent' => 4, 'replied' => 1, 'bounced' => 2, 'unsubscribed' => 1, 'exited' => 4]);
    expect($people['reply']->fresh()->score)->toBeGreaterThanOrEqual(8);
});

it('lets staff build, test, preview, start and fill campaigns', function () {
    $as = fn () => $this->actingAs($this->staff);
    $as()->post('/admin/growth/campaigns', ['name' => 'Austin installers'])->assertRedirect();
    $c = Campaign::where('name', 'Austin installers')->firstOrFail();
    $step = $c->steps()->firstOrFail();
    $as()->patch("/admin/growth/campaigns/{$c->ulid}/steps/{$step->id}", ['wait_hours' => 0, 'subject' => 'Seats in {city}', 'subject_b' => 'Your {city} seat', 'body' => "Hi {first_name},\n\nA seat is open.",
        'cta_label' => 'See', 'cta_url' => 'javascript:alert(1)'])->assertSessionHasErrors('cta_url');
    $as()->patch("/admin/growth/campaigns/{$c->ulid}/steps/{$step->id}", ['wait_hours' => 0, 'subject' => 'Seats in {city}', 'subject_b' => 'Your {city} seat', 'body' => "Hi {first_name},\n\nA seat is open.",
        'cta_label' => 'See', 'cta_url' => '/groups'])->assertSessionHas('status', 'campaign-saved');
    $as()->post("/admin/growth/campaigns/{$c->ulid}/steps/{$step->id}/test")->assertSessionHasErrors('test'); // postal address first
    app(Settings::class)->set('general.postal_address', '1 Main St, Austin, TX 78701');
    $as()->post("/admin/growth/campaigns/{$c->ulid}/steps/{$step->id}/test")->assertSessionHas('status', 'campaign-test-sent');
    Notification::assertSentOnDemand(GrowthMessage::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'sam@t2t.example' && str_starts_with($n->toMail($to)->subject, '[Test] Seats in Austin'));
    expect(DB::table('campaign_sends')->count())->toBe(0); // tests aren't counted
    $html = (string) $as()->get("/admin/growth/campaigns/{$c->ulid}/steps/{$step->id}/preview")->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN')->getContent();
    expect($html)->toContain('Hi Sam')->toContain('1 Main St, Austin');
    $as()->patch("/admin/growth/campaigns/{$c->ulid}", ['name' => 'Austin installers', 'goal' => 'joined', 'window_start' => 18, 'window_end' => 9, 'daily_cap' => 100])->assertSessionHasErrors('window_end');
    $as()->post("/admin/growth/campaigns/{$c->ulid}/status", ['status' => 'active'])->assertSessionHas('status', 'campaign-started');
    $p = Prospects::upsert(['email' => 'pat@example.com'], 'manual', 'added_by_staff');
    $as()->post('/admin/growth/campaigns/enroll', ['campaign' => $c->ulid, 'ids' => [$p->ulid]])->assertSessionHas('enrolled', 1);
    $as()->post('/admin/growth/campaigns/enroll', ['campaign' => $c->ulid, 'ids' => [$p->ulid]])->assertSessionHas('enrolled', 0);
    $as()->get("/admin/growth/campaigns/{$c->ulid}")->assertInertia(fn ($pg) => $pg->component('growth/Campaign')->where('people.0.status', 'active')->has('steps', 1));
    $as()->get('/admin/growth/prospects')->assertInertia(fn ($pg) => $pg->where('campaigns.0.name', 'Austin installers'));
    $this->actingAs(User::factory()->create())->get('/admin/growth/campaigns')->assertForbidden();
});

it('remembers where visitors came from, on public pages only', function () {
    $this->postJson('/visit', ['path' => '/industries/solar', 'referrer' => 'https://www.bing.com/search?q=x', 'utm' => []])->assertNoContent()
        ->assertCookie(Attribution::FIRST)->assertCookie(Attribution::LAST);
    $r = $this->postJson('/visit', ['path' => '/', 'referrer' => '', 'utm' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'jan']]);
    expect(json_decode((string) $r->getCookie(Attribution::LAST)?->getValue(), true))->toMatchArray(['source' => 'newsletter', 'campaign' => 'jan']);
    $this->postJson('/visit', ['path' => '/app', 'referrer' => '', 'utm' => ['source' => 'x']])->assertCookieMissing(Attribution::LAST);
    expect(Attribution::visit('/x', 'https://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/y', [], (string) parse_url((string) config('app.url'), PHP_URL_HOST)))->toMatchArray(['source' => 'direct', 'referrer' => null]);
    // A member signed in is never tracked.
    $this->actingAs(User::factory()->create())->postJson('/visit', ['path' => '/', 'utm' => ['source' => 'x']])->assertCookieMissing(Attribution::FIRST);
    // The open pixel is a real 1×1 GIF.
    $this->get('/g/o/'.str_repeat('a', 26).'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect(Tracking::valid('x', 'https://a.example', 'nope'))->toBeFalse();
});

it('doesn’t send the old sign-up reminders while the sequence is looking after someone', function () {
    ($this->on)();
    $user = User::factory()->create(['created_at' => now()->subDays(2)->subHour()]);
    Prospects::onSignup($user);
    Campaigns::onSignup($user);
    Artisan::call('t2t:onboarding:nudges');
    Notification::assertNotSentTo($user, InAppNotification::class, fn ($n) => $n->type === 'onboarding.nudge');
    $other = User::factory()->create(['created_at' => now()->subDays(2)->subHour()]);
    Artisan::call('t2t:onboarding:nudges');
    Notification::assertSentTo($other, InAppNotification::class, fn ($n) => $n->type === 'onboarding.nudge');
});
