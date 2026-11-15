<?php

use App\Domains\Api\ApiTokens;
use App\Domains\Api\Webhooks\Webhooks;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Integrations\Calendar\CalendarConnection;
use App\Domains\Integrations\Calendar\CalendarFeed;
use App\Domains\Integrations\Calendar\CalendarItems;
use App\Domains\Integrations\Calendar\CalendarSync;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referrals;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    fakeSms();
    Cache::flush();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->cities = City::where('is_enabled', true)->orderByDesc('population')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create(['timezone' => 'America/Chicago'])))->update(['name' => $n]);
    $this->a = $mk('Sender Solar');
    $this->b = $mk('Holder Solar');
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $this->send = function (array $over = []) {
        $answers = [];
        foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
            $answers[$f['key']] = match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text',
            };
        }

        return app(Referrals::class)->send($this->a->leader, $this->g, $this->cities[1], [...$answers, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100', 'client_email' => 'tom@example.com', ...$over], true, null)['referral'];
    };
    $this->event = function (array $over = []) {
        $id = DB::table('events')->insertGetId(['group_id' => $this->g->id, 'created_by' => $this->a->leader->id, 'title' => 'Austin mixer', 'description' => 'Meet the network', 'kind' => 'in_person',
            'location' => '100 Congress Ave, Austin', 'starts_at' => now()->addDays(3)->setTime(18, 0), 'ends_at' => now()->addDays(3)->setTime(20, 0), 'timezone' => 'America/Chicago',
            'check_in_code' => Str::random(8), 'created_at' => now(), 'updated_at' => now(), ...$over]);
        DB::table('event_rsvps')->insert(['event_id' => $id, 'user_id' => $this->b->leader->id, 'team_id' => $this->b->id, 'status' => 'going', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
});

it('puts events, referral deadlines and future-lead check-ins in a person’s calendar, privately', function () {
    $ev = ($this->event)();
    ($this->event)(['title' => 'Cancelled thing', 'cancelled_at' => now(), 'check_in_code' => 'CANCEL01']);
    $pending = ($this->send)();
    $accepted = ($this->send)(['client_email' => 'jo@example.com', 'client_phone' => '+14355550199', 'client_name' => 'Jo Lee']);
    app(Referrals::class)->accept($accepted, $this->b->leader);
    DB::table('referrals')->where('id', $accepted->id)->update(['nurture_next_at' => now()->addDays(10)->toDateString()]);
    $items = collect(CalendarItems::for($this->b->leader))->keyBy('key');
    expect($items->keys()->sort()->values()->all())->toBe(collect(["event:{$ev}", "respond:{$pending->ulid}", "contact:{$accepted->ulid}", "nurture:{$accepted->ulid}"])->sort()->values()->all());
    expect($items["respond:{$pending->ulid}"]['title'])->toBe('Respond to the referral for Tom B.') // no full name before accepting
        ->and($items["respond:{$pending->ulid}"]['end']->equalTo($pending->respond_by))->toBeTrue()
        ->and($items["contact:{$accepted->ulid}"]['title'])->toBe('First contact due: Jo Lee')
        ->and($items["nurture:{$accepted->ulid}"]['all_day'])->toBeTrue()
        ->and($items["event:{$ev}"]['location'])->toBe('100 Congress Ave, Austin');
    // After first contact, the next update is what's due.
    app(Pipeline::class)->logCall($accepted->fresh(), $this->b->leader, 'Spoke to Jo');
    expect(collect(CalendarItems::for($this->b->leader))->pluck('key'))->toContain("update:{$accepted->ulid}")->not->toContain("contact:{$accepted->ulid}");
    // The sender's own calendar has no deadlines for referrals it sent; kinds can be picked.
    expect(CalendarItems::for($this->a->leader))->toBe([]);
    expect(collect(CalendarItems::for($this->b->leader, ['events']))->pluck('kind')->unique()->all())->toBe(['events']);
});

it('serves a private calendar link that any calendar app can subscribe to', function () {
    ($this->event)();
    ($this->send)();
    ($this->as)($this->b->leader)->post('/app/calendar/link')->assertSessionHas('status', 'calendar-link-created');
    $url = CalendarFeed::url($this->b->leader->fresh());
    expect($url)->toMatch('#/calendar/[A-Za-z0-9]{40}\.ics$#');
    $path = parse_url($url, PHP_URL_PATH);
    $r = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($r->headers->get('X-Robots-Tag'))->toContain('noindex');
    $body = (string) $r->getContent();
    expect($body)->toStartWith("BEGIN:VCALENDAR\r\n")->toContain('SUMMARY:Austin mixer')->toContain('SUMMARY:Respond to the referral for Tom B.')->not->toContain('Brennan')->toEndWith("END:VCALENDAR\r\n");
    foreach (explode("\r\n", $body) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
    ($this->as)($this->b->leader)->get('/app/integrations')->assertInertia(fn ($p) => $p->where('calendar.link', $url));
    // A new link stops the old one; turning it off stops it too.
    ($this->as)($this->b->leader)->post('/app/calendar/link');
    $this->get($path)->assertNotFound();
    ($this->as)($this->b->leader)->delete('/app/calendar/link');
    $this->get(parse_url((string) CalendarFeed::url($this->b->leader->fresh()), PHP_URL_PATH) ?: '/calendar/'.str_repeat('a', 40).'.ics')->assertNotFound();
    expect($this->b->leader->fresh()->toArray())->not->toHaveKey('calendar_feed_token');
});

/** Google's token endpoint and Calendar API, keeping the events written. */
function fakeGoogle(array &$g): void
{
    $g += ['events' => [], 'refuse' => false, 'tokens' => 0];
    Http::fake(function (HttpRequest $r) use (&$g) {
        $url = $r->url();
        if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
            $g['tokens']++;
            if ($g['refuse']) {
                return Http::response(['error' => 'invalid_grant'], 400);
            }
            $claims = rtrim(strtr(base64_encode(json_encode(['email' => 'holly@gmail.com'])), '+/', '-_'), '=');

            return Http::response(['access_token' => 'at-'.$g['tokens'], 'expires_in' => 3600, 'refresh_token' => 'rt-1', 'id_token' => "h.{$claims}.s"]);
        }
        if (str_contains($url, '/users/me/calendarList')) {
            return Http::response(['items' => [['id' => 'primary-id', 'summary' => 'Holly', 'primary' => true], ['id' => 'work@group.calendar.google.com', 'summary' => 'Work']]]);
        }
        if (preg_match('#/calendars/([^/]+)/events(?:/([^?]+))?#', $url, $m)) {
            $cal = urldecode($m[1]);
            $id = isset($m[2]) ? urldecode($m[2]) : null;

            return match ($r->method()) {
                'POST' => (function () use (&$g, $r, $cal) {
                    $id = 'g'.(count($g['events']) + 1);
                    $g['events'][$id] = ['cal' => $cal, ...$r->data()];

                    return Http::response(['id' => $id]);
                })(),
                'PUT' => isset($g['events'][$id]) ? (function () use (&$g, $r, $id, $cal) {
                    $g['events'][$id] = ['cal' => $cal, ...$r->data()];

                    return Http::response(['id' => $id]);
                })() : Http::response([], 404),
                'DELETE' => (function () use (&$g, $id) {
                    unset($g['events'][$id]);

                    return Http::response(null, 204);
                })(),
                default => Http::response([], 405),
            };
        }

        return Http::response([], 404);
    });
}

it('connects Google Calendar and keeps it in step: adds, updates and removes items', function () {
    $s = app(Settings::class);
    $s->set('integrations.google.client_id', 'gid.apps.googleusercontent.com');
    $s->set('integrations.google.client_secret', 'gsecret', true);
    $g = [];
    fakeGoogle($g);
    $ev = ($this->event)();
    $ref = ($this->send)();

    $r = ($this->as)($this->b->leader)->withHeader('X-Inertia', 'true')->post('/app/calendar/google/connect');
    $r->assertStatus(409);
    $auth = (string) $r->headers->get('X-Inertia-Location');
    parse_str((string) parse_url($auth, PHP_URL_QUERY), $q);
    expect($auth)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')->and($q)->toMatchArray(['client_id' => 'gid.apps.googleusercontent.com', 'access_type' => 'offline', 'prompt' => 'consent',
        'redirect_uri' => url('/app/calendar/google/callback')])->and($q['scope'])->toContain('calendar.events');
    $this->flushHeaders();
    ($this->as)($this->b->leader)->get('/app/calendar/google/callback?state=wrong&code=x')->assertRedirect('/app/integrations')->assertSessionHasErrors('calendar');
    ($this->as)($this->b->leader)->withSession(['calendar_oauth' => ['state' => $q['state'], 'provider' => 'google']])
        ->get('/app/calendar/google/callback?state='.$q['state'].'&code=auth-code')->assertRedirect('/app/integrations')->assertSessionHas('status', 'calendar-connected');
    $c = CalendarConnection::firstOrFail();
    expect($c)->account_email->toBe('holly@gmail.com')->status->toBe('active')->and($c->getRawOriginal('credentials'))->not->toContain('rt-1');
    expect(collect($g['events'])->pluck('summary')->sort()->values()->all())->toBe(['Austin mixer', 'Respond to the referral for Tom B.']);
    $mixer = collect($g['events'])->firstWhere('summary', 'Austin mixer');
    expect($mixer['location'])->toBe('100 Congress Ave, Austin')->and($mixer['start']['timeZone'])->toBe('UTC')->and($mixer['source']['url'])->toBe(url('/app/events/'.$ev));

    // Nothing changed: nothing written. A change updates in place; a cancelled event and an answered referral go away.
    Http::assertSentCount(3);
    expect(CalendarSync::sync($c->fresh()))->toBe(['added' => 0, 'updated' => 0, 'removed' => 0]);
    DB::table('events')->where('id', $ev)->update(['title' => 'Austin mixer (new venue)']);
    expect(CalendarSync::sync($c->fresh()))->toBe(['added' => 0, 'updated' => 1, 'removed' => 0]);
    app(Referrals::class)->accept($ref, $this->b->leader);
    DB::table('events')->where('id', $ev)->update(['cancelled_at' => now()]);
    expect(CalendarSync::sync($c->fresh()))->toMatchArray(['removed' => 2, 'added' => 1]); // respond-by gone, first-contact due added
    expect(collect($g['events'])->pluck('summary')->all())->toBe(['First contact due: Tom Brennan']);

    // Choosing another calendar moves the items there.
    ($this->as)($this->b->leader)->getJson("/app/calendar/{$c->ulid}/calendars")->assertOk()->assertJsonPath('1.name', 'Work');
    ($this->as)($this->b->leader)->patch("/app/calendar/{$c->ulid}", ['calendar_id' => 'work@group.calendar.google.com', 'kinds' => ['events', 'deadlines']])->assertSessionHas('status', 'calendar-updated');
    expect(collect($g['events'])->pluck('cal')->unique()->all())->toBe(['work@group.calendar.google.com'])->and($c->fresh()->calendar_name)->toBe('Work');

    // An expired access token is refreshed; a removed one marks the connection and tells the person once.
    $this->travel(2)->hours();
    $before = $g['tokens'];
    CalendarSync::sync($c->fresh());
    expect($g['tokens'])->toBe($before + 1);
    $this->travel(2)->hours();
    $g['refuse'] = true;
    CalendarSync::syncAll();
    CalendarSync::syncAll();
    expect($c->fresh()->status)->toBe('error');
    expect(Notification::sent($this->b->leader, InAppNotification::class)->filter(fn ($n) => $n->type === 'integrations.attention')->count())->toBe(1);

    // Disconnecting removes what we added.
    $g['refuse'] = false;
    $c->fresh()->forceFill(['status' => 'active'])->save();
    ($this->as)($this->b->leader)->delete("/app/calendar/{$c->ulid}")->assertSessionHas('status', 'calendar-disconnected');
    expect($g['events'])->toBe([])->and(CalendarConnection::count())->toBe(0);
    ($this->as)($this->a->leader)->get('/app/calendar/'.$c->ulid.'/calendars')->assertNotFound();
});

it('writes Outlook events through Microsoft Graph, all-day items included', function () {
    $s = app(Settings::class);
    $s->set('integrations.microsoft.client_id', 'ms-client');
    $s->set('integrations.microsoft.client_secret', 'ms-secret', true);
    $made = [];
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'ms-at', 'refresh_token' => 'ms-rt', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/me?*' => Http::response(['mail' => 'holly@outlook.com']),
        'graph.microsoft.com/v1.0/me/events' => function (HttpRequest $r) use (&$made) {
            $made[] = $r->data();

            return Http::response(['id' => 'm'.count($made)], 201);
        },
    ]);
    $ref = ($this->send)();
    app(Referrals::class)->accept($ref, $this->b->leader);
    DB::table('referrals')->where('id', $ref->id)->update(['nurture_next_at' => now()->addDays(10)->toDateString()]);
    $c = CalendarSync::connect($this->b->leader, 'microsoft', 'code');
    expect($c->account_email)->toBe('holly@outlook.com')->and($made)->toHaveCount(2);
    $allDay = collect($made)->firstWhere('isAllDay', true);
    expect($allDay['subject'])->toBe('Check in with Tom Brennan')->and($allDay['start']['dateTime'])->toEndWith('T00:00:00')->and($allDay['categories'])->toBe(['Team to Team Referrals']);
});

it('shows calendars as available only once an admin sets them up', function () {
    ($this->as)($this->b->leader)->get('/app/integrations')->assertInertia(fn ($p) => $p->where('calendar.providers.0.ready', false)->where('calendar.providers.1.ready', false));
    ($this->as)($this->b->leader)->post('/app/calendar/google/connect')->assertNotFound();
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $this->actingAs($admin)->post('/admin/integrations', ['calendar' => ['google' => ['enabled' => true, 'client_id' => 'gid', 'client_secret' => 'gsecret']], 'zapier_invite' => 'https://example.com/x'])
        ->assertSessionHasErrors('zapier_invite');
    $this->actingAs($admin)->post('/admin/integrations', ['calendar' => ['google' => ['enabled' => true, 'client_id' => 'gid', 'client_secret' => 'gsecret']], 'zapier_invite' => 'https://zapier.com/developer/public-invite/123/abc/'])
        ->assertSessionHas('status', 'integrations-saved');
    $this->actingAs($admin)->get('/admin/integrations')->assertInertia(fn ($p) => $p->where('calendars.0.secret', '••••••••cret')->where('calendars.0.redirect', url('/app/calendar/google/callback')));
    ($this->as)($this->b->leader)->get('/app/integrations')->assertInertia(fn ($p) => $p->where('calendar.providers.0.ready', true));
    ($this->as)($this->b->leader)->get('/app/developers')->assertInertia(fn ($p) => $p->where('zapierInvite', 'https://zapier.com/developer/public-invite/123/abc/'));
});

it('gives Zapier its own webhook allowance, and finds referrals by email only where the email is visible', function () {
    Webhooks::resolveUsing(fn () => ['93.184.216.34']);
    $tok = ApiTokens::create($this->b, $this->b->leader, 'Zapier', 'live', array_keys(ApiTokens::SCOPES))['plain'];
    $api = fn () => $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$tok, 'Accept' => 'application/json']);
    $api()->postJson('/api/v1/webhooks', ['url' => 'https://own.example.com/hook'])->assertCreated();
    $api()->postJson('/api/v1/webhooks', ['url' => 'https://another.example.com/hook'])->assertStatus(422);
    foreach (range(1, 3) as $i) {
        $api()->postJson('/api/v1/webhooks', ['url' => "https://hooks.zapier.com/hooks/standard/1/{$i}/", 'events' => ['referral.sent']])->assertCreated();
    }
    expect(Webhooks::endpointsFor($this->b))->toHaveCount(4);
    Webhooks::resolveUsing(null);

    $ref = ($this->send)();
    $api()->getJson('/api/v1/referrals?client_email=tom@example.com')->assertOk()->assertJsonCount(0, 'data'); // received, not accepted: hidden
    app(Referrals::class)->accept($ref, $this->b->leader);
    $api()->getJson('/api/v1/referrals?client_email=TOM@example.com')->assertOk()->assertJsonPath('data.0.id', $ref->ulid);
    $sender = ApiTokens::create($this->a, $this->a->leader, 'S', 'live', ['referrals:read'])['plain'];
    $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$sender])->getJson('/api/v1/referrals?client_email=tom@example.com')->assertJsonPath('data.0.id', $ref->ulid);
});

it('uses only API routes that exist, in the Zapier app', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' '.preg_replace('/\{[^}]+\}/', '*', '/'.$r->uri())))->all();
    $used = [];
    foreach (glob(base_path('integrations/zapier/{triggers,creates,searches}/*.js'), GLOB_BRACE) ?: [] as $file) {
        preg_match_all("/call\\(z, '([A-Z]+)', [`']?(\\/[^`',]+)/", (string) file_get_contents($file), $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $used[] = $hit[1].' /api/v1'.preg_replace('/\$\{[^}]+\}/', '*', $hit[2]);
        }
    }
    // The sample lists (performList) read these.
    $paths = ['GET /api/v1/referrals', 'GET /api/v1/leads'];
    expect($used)->not->toBeEmpty();
    foreach ([...$used, ...array_values($paths)] as $u) {
        expect($routes)->toContain($u);
    }
});
