<?php

use App\Domains\Api\ApiTokens;
use App\Domains\Api\Webhooks\WebhookDelivery;
use App\Domains\Api\Webhooks\WebhookEndpoint;
use App\Domains\Api\Webhooks\Webhooks;
use App\Domains\Billing\Invoicing;
use App\Domains\Billing\RevenueTypes;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Dashboard\NetworkKpis;
use App\Domains\Referrals\Dashboard\Period;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

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
    // Every host resolves to a public address unless a test says otherwise.
    Webhooks::resolveUsing(fn (string $host) => str_contains($host, 'internal') ? ['10.0.0.5'] : ['93.184.216.34']);
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->cities = City::where('is_enabled', true)->orderByDesc('population')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    $this->a = $mk('Sender Solar');
    $this->b = $mk('Holder Solar');
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $this->token = fn (Team $t, string $mode = 'live') => ApiTokens::create($t, $t->leader, 'Test', $mode, array_keys(ApiTokens::SCOPES))['plain'];
    $this->api = fn (string $token) => $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    $this->hook = fn (Team $t, string $url = 'https://hooks.example.com/t2t', string $mode = 'live', array $events = ['*']) => Webhooks::create($t, $t->leader, $url, $mode, $events, null);
    $this->answers = function (Group $g): array {
        $a = [];
        foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
            $a[$f['key']] = match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text',
            };
        }

        return [...$a, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100', 'client_email' => 'tom@example.com'];
    };
    // The signature check exactly as published on /developers (PHP version).
    preg_match('/const VERIFY_PHP = `(.*?)`;/s', (string) file_get_contents(base_path('resources/js/pages/Developers.tsx')), $m);
    if (! function_exists('isFromTeamToTeam')) {
        eval($m[1]);
    }
});

afterEach(fn () => Webhooks::resolveUsing(null));

/** @return list<array{type: string, body: array<string, mixed>, raw: string, signature: string}> */
function received(string $url): array
{
    $out = [];
    foreach (Http::recorded(fn (HttpRequest $r) => $r->url() === $url) as [$r]) {
        $out[] = ['type' => $r->header('T2T-Event-Type')[0], 'body' => json_decode($r->body(), true), 'raw' => $r->body(), 'signature' => $r->header('T2T-Signature')[0]];
    }

    return $out;
}

it('sends a signed webhook for every change to a referral created by API (the 44 "done when")', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok'), 'b.example.com/*' => Http::response('ok')]);
    $sender = ($this->hook)($this->a);
    $receiver = ($this->hook)($this->b, 'https://b.example.com/hooks');
    $send = ($this->token)($this->a);
    $recv = ($this->token)($this->b);
    $id = ($this->api)($send)->postJson('/api/v1/referrals', ['group' => 'solar-installers-network', 'city_id' => $this->cities[1]->id, 'answers' => ($this->answers)($this->g), 'consent' => true])->assertCreated()->json('id');
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/accept")->assertOk();
    $stages = ($this->api)($recv)->getJson('/api/v1/groups/solar-installers-network')->json('stages');
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/stage", ['stage' => collect($stages)->firstWhere('kind', 'open')['key']])->assertOk();
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/notes", ['note' => 'Booked'])->assertCreated();
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/stage", ['stage' => collect($stages)->firstWhere('kind', 'won')['key'], 'closing_date' => now()->toDateString(), 'closing_value' => 1000, 'earnings' => 100])->assertOk();

    $got = received('https://hooks.example.com/t2t');
    expect(array_column($got, 'type'))->toBe(['referral.sent', 'referral.accepted', 'referral.stage_changed', 'referral.note_added', 'referral.stage_changed']);
    foreach ($got as $g) {
        expect(isFromTeamToTeam($g['raw'], $g['signature'], $sender['secret']))->toBeTrue()
            ->and(isFromTeamToTeam($g['raw'], $g['signature'], 'whsec_wrong'))->toBeFalse()
            ->and(isFromTeamToTeam($g['raw'].' ', $g['signature'], $sender['secret']))->toBeFalse()
            ->and($g['body'])->toMatchArray(['object' => 'event', 'api_version' => 'v1', 'livemode' => true])
            ->and($g['body']['data']['object']['id'])->toBe($id);
    }
    expect(end($got)['body']['data']['object']['stage']['kind'])->toBe('won');
    // The receiving team's copy follows its own privacy rules: no contact details until it accepted.
    $b = received('https://b.example.com/hooks');
    expect($b[0]['type'])->toBe('referral.sent')->and($b[0]['body']['data']['object']['client'])->toMatchArray(['name' => 'Tom B.', 'phone' => null, 'email' => null])
        ->and($b[1]['body']['data']['object']['client']['phone'])->toBe('+14355550100')
        ->and(isFromTeamToTeam($b[0]['raw'], $b[0]['signature'], $receiver['secret']))->toBeTrue();
    expect(WebhookDelivery::where('status', 'succeeded')->count())->toBe(10);
    // An old timestamp is refused by the published check.
    $old = now()->subMinutes(10)->getTimestamp();
    expect(isFromTeamToTeam('{}', "t={$old},v1=".Webhooks::sign($sender['secret'], $old, '{}'), $sender['secret']))->toBeFalse();
});

it('verifies with the published Node.js snippet too', function () {
    preg_match('/const VERIFY_NODE = `(.*?)`;/s', (string) file_get_contents(base_path('resources/js/pages/Developers.tsx')), $m);
    $secret = 'whsec_'.str_repeat('a', 40);
    $body = '{"id":"evt_1","type":"ping"}';
    $t = now()->getTimestamp();
    $header = "t={$t},v1=".Webhooks::sign('whsec_old', $t, $body).',v1='.Webhooks::sign($secret, $t, $body);
    $script = str_replace("import crypto from 'node:crypto';", "const crypto = require('node:crypto');", $m[1])
        ."\nconsole.log(JSON.stringify([isFromTeamToTeam(process.argv[1], process.argv[2], process.argv[3]), isFromTeamToTeam(process.argv[1], process.argv[2], 'nope')]));";
    $r = Process::run(['node', '-e', $script, $body, $header, $secret]);
    expect(trim($r->output()))->toBe('[true,false]');
});

it('retries failures for about a day, switches off an endpoint failing for 3 days, and can send again', function () {
    $up = false;
    Http::fake(function () use (&$up) {
        return $up ? Http::response('ok') : Http::response('down', 503);
    });
    $e = ($this->hook)($this->a)['endpoint'];
    Webhooks::ping($e);
    $d = WebhookDelivery::firstOrFail();
    expect($d->status)->toBe('retrying')->and($d->attempts)->toBe(1)->and($d->response_status)->toBe(503)->and($d->error)->toContain('503')
        ->and($d->next_attempt_at->diffInSeconds(now(), true))->toBeBetween(55.0, 65.0);
    expect(Webhooks::retryDue())->toBe(0); // not due yet
    $this->travel(61)->seconds();
    Webhooks::retryDue();
    expect($d->fresh()->attempts)->toBe(2);
    foreach ([300, 1800, 7200, 18000, 36000, 21600] as $wait) {
        $this->travel($wait + 1)->seconds();
        Webhooks::retryDue();
    }
    $d->refresh();
    expect($d->attempts)->toBe(Webhooks::MAX_ATTEMPTS)->and($d->status)->toBe('failed')->and($d->next_attempt_at)->toBeNull();
    expect(now()->diffInHours($d->created_at, true))->toBeBetween(23.0, 25.0);
    expect($e->fresh()->is_active)->toBeTrue();
    // Still failing three days after the first failure: switched off, and the team is told.
    $this->travel(49)->hours();
    Webhooks::ping($e);
    expect($e->fresh())->is_active->toBeFalse()->disabled_reason->toContain('3 days');
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => $n->type === 'api.webhook_disabled');
    expect(fn () => Webhooks::resend($d))->toThrow(Illuminate\Validation\ValidationException::class);

    // Fixed and switched back on: "Send again" delivers the same event (same id) and clears the failure clock.
    $up = true;
    Webhooks::update($e->fresh(), $this->a->leader, ['active' => true]);
    $again = Webhooks::resend($d);
    expect($again->status)->toBe('succeeded')->and($again->event_id)->toBe($d->event_id)->and($e->fresh()->failing_since)->toBeNull();
});

it('only sends to public https addresses, without following redirects', function () {
    expect(fn () => ($this->hook)($this->a, 'http://hooks.example.com/x'))->toThrow(Illuminate\Validation\ValidationException::class);
    foreach (['https://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://[::1]/x', 'https://100.64.1.1/x', 'https://api.internal.example/x', 'https://user:pw@hooks.example.com/x'] as $bad) {
        expect(fn () => ($this->hook)($this->a, $bad))->toThrow(Illuminate\Validation\ValidationException::class);
    }
    $this->actingAs($this->a->leader)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/app/developers/webhooks', ['url' => 'https://10.1.2.3/hook', 'mode' => 'live'])->assertSessionHasErrors(['url' => 'That address points to a private or reserved network. Webhooks can only go to public servers.']);
    // A host that later points somewhere private is refused at send time.
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/'])]);
    $e = ($this->hook)($this->a, 'https://moves.example.com/x')['endpoint'];
    Webhooks::resolveUsing(fn () => ['192.168.1.10']);
    $d = Webhooks::ping($e);
    expect($d->status)->toBe('retrying')->and($d->error)->toContain('private');
    Http::assertNothingSent();
    // A redirect is a failure, not followed.
    Webhooks::resolveUsing(fn () => ['93.184.216.34']);
    expect(Webhooks::ping($e))->status->toBe('retrying')->response_status->toBe(302);
    Http::assertSentCount(1);
});

it('signs with both secrets for 24 hours after a rotation', function () {
    Http::fake(['*' => Http::response('ok')]);
    $r = ($this->hook)($this->a);
    $new = Webhooks::rotate($r['endpoint'], $this->a->leader);
    Webhooks::ping($r['endpoint']->fresh());
    $g = received('https://hooks.example.com/t2t')[0];
    expect(substr_count($g['signature'], 'v1='))->toBe(2)->and(isFromTeamToTeam($g['raw'], $g['signature'], $r['secret']))->toBeTrue()->and(isFromTeamToTeam($g['raw'], $g['signature'], $new))->toBeTrue();
    $this->travel(25)->hours();
    Webhooks::prune();
    Webhooks::ping($r['endpoint']->fresh());
    $g = received('https://hooks.example.com/t2t')[1];
    expect(substr_count($g['signature'], 'v1='))->toBe(1)->and(isFromTeamToTeam($g['raw'], $g['signature'], $r['secret']))->toBeFalse();
    expect(WebhookEndpoint::firstOrFail()->getRawOriginal('secret'))->not->toContain('whsec_'); // stored encrypted
});

it('sends sandbox events to test endpoints only, and respects event choices and plan limits', function () {
    Http::fake(['*' => Http::response('ok')]);
    ($this->hook)($this->a, 'https://live.example.com/x', 'live');
    expect(fn () => ($this->hook)($this->a, 'https://test.example.com/x', 'test'))->toThrow(Illuminate\Validation\ValidationException::class, 'API plan');
    $this->a->forceFill(['api_plan_since' => now()])->save();
    ($this->hook)($this->a, 'https://test.example.com/x', 'test');
    ($this->hook)($this->a, 'https://accepted-only.example.com/x', 'live', ['referral.accepted']);
    $test = ($this->token)($this->a, 'test');
    ($this->api)($test)->getJson('/api/v1/me')->assertOk()->assertJsonPath('rate_limit_per_minute', 600);
    $sg = Group::where('slug', 'api-sandbox-solar')->firstOrFail();
    $sample = collect(($this->api)($test)->getJson('/api/v1/groups/api-sandbox-solar')->json('sandbox_seats'))->firstWhere('yours', false);
    $ref = ($this->api)($test)->postJson('/api/v1/referrals', ['group' => 'api-sandbox-solar', 'city_id' => $sample['city_id'], 'answers' => ($this->answers)($sg), 'consent' => true])->assertCreated()->json('id');
    ($this->api)($test)->postJson("/api/v1/sandbox/referrals/{$ref}/simulate", ['action' => 'accept'])->assertOk();
    expect(array_column(received('https://test.example.com/x'), 'type'))->toBe(['referral.sent', 'referral.accepted'])
        ->and(received('https://test.example.com/x')[0]['body']['livemode'])->toBeFalse()
        ->and(received('https://live.example.com/x'))->toBe([])->and(received('https://accepted-only.example.com/x'))->toBe([]);
    // A real referral: live endpoints, filtered by their event choices.
    $id = ($this->api)(($this->token)($this->a))->postJson('/api/v1/referrals', ['group' => 'solar-installers-network', 'city_id' => $this->cities[1]->id, 'answers' => ($this->answers)($this->g), 'consent' => true])->json('id');
    ($this->api)(($this->token)($this->b))->postJson("/api/v1/referrals/{$id}/accept")->assertOk();
    expect(array_column(received('https://live.example.com/x'), 'type'))->toBe(['referral.sent', 'referral.accepted'])
        ->and(array_column(received('https://accepted-only.example.com/x'), 'type'))->toBe(['referral.accepted'])
        ->and(count(received('https://test.example.com/x')))->toBe(2);
    // Plan switched off: only the oldest endpoint keeps receiving; the others show as paused.
    $this->a->forceFill(['api_plan_until' => now()])->save();
    ($this->api)(($this->token)($this->b))->postJson("/api/v1/referrals/{$id}/notes", ['note' => 'x'])->assertCreated();
    expect(count(received('https://live.example.com/x')))->toBe(3)->and(count(received('https://accepted-only.example.com/x')))->toBe(1);
    $this->actingAs($this->a->leader)->withSession(['auth.password_confirmed_at' => time()])->get('/app/developers')
        ->assertInertia(fn ($p) => $p->where('webhooks.0.overLimit', false)->where('webhooks.1.overLimit', true)->where('plan.on', false));
});

it('manages endpoints by API with the webhooks:manage permission', function () {
    Http::fake(['*' => Http::response('ok')]);
    $live = ($this->token)($this->a);
    $created = ($this->api)($live)->postJson('/api/v1/webhooks', ['url' => 'https://zap.example.com/hook', 'events' => ['referral.sent', 'referral.accepted'], 'description' => 'Zapier'])
        ->assertCreated()->assertJson(['mode' => 'live', 'events' => ['referral.sent', 'referral.accepted'], 'active' => true])->json();
    expect($created['secret'])->toStartWith('whsec_');
    ($this->api)($live)->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
    ($this->api)(($this->token)($this->a, 'test'))->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(0, 'data');
    ($this->api)($live)->postJson('/api/v1/webhooks', ['url' => 'https://second.example.com/hook'])->assertStatus(422)->assertJsonPath('errors.url.0', fn ($m) => str_contains($m, 'API plan'));
    ($this->api)($live)->postJson('/api/v1/webhooks', ['url' => 'https://x.example.com', 'events' => ['nope']])->assertStatus(422);
    $test = ($this->api)($live)->postJson("/api/v1/webhooks/{$created['id']}/test")->assertCreated()->assertJson(['event_type' => 'ping', 'status' => 'succeeded', 'response_status' => 200])->json();
    ($this->api)($live)->getJson("/api/v1/webhooks/{$created['id']}/deliveries")->assertOk()->assertJsonPath('data.0.id', $test['id'])->assertJsonPath('data.0.payload.type', 'ping');
    ($this->api)($live)->postJson("/api/v1/webhook-deliveries/{$test['id']}/resend")->assertCreated()->assertJsonPath('event_id', $test['event_id']);
    $rotated = ($this->api)($live)->postJson("/api/v1/webhooks/{$created['id']}/rotate-secret")->assertOk()->json('secret');
    expect($rotated)->not->toBe($created['secret']);
    ($this->api)($live)->patchJson("/api/v1/webhooks/{$created['id']}", ['active' => false, 'events' => ['*']])->assertOk()->assertJson(['active' => false, 'events' => ['*']]);
    ($this->api)(($this->token)($this->b))->getJson("/api/v1/webhooks/{$created['id']}")->assertNotFound();
    $noScope = ApiTokens::create($this->a, $this->a->leader, 'R', 'live', ['referrals:read'])['plain'];
    ($this->api)($noScope)->getJson('/api/v1/webhooks')->assertForbidden();
    ($this->api)($live)->deleteJson("/api/v1/webhooks/{$created['id']}")->assertNoContent();
    expect(WebhookEndpoint::count())->toBe(0)->and(WebhookDelivery::count())->toBe(0);
    expect(DB::table('activity_log')->where('event', 'like', 'webhook-%')->pluck('event')->all())->toBe(['webhook-created', 'webhook-secret-rotated', 'webhook-updated', 'webhook-deleted']);
});

it('lets leaders and assistants manage webhooks in the portal; secrets are shown once', function () {
    Http::fake(['*' => Http::response('ok')]);
    $as = fn (User $u) => $this->actingAs($u)->withSession(['auth.password_confirmed_at' => time()]);
    $as($this->a->leader)->post('/app/developers/webhooks', ['url' => 'https://hooks.example.com/t2t', 'mode' => 'live', 'description' => 'CRM', 'events' => ['*']])
        ->assertSessionHas('status', 'webhook-created')->assertSessionHas('new_secret', fn ($s) => str_starts_with($s['secret'], 'whsec_'));
    $e = WebhookEndpoint::firstOrFail();
    $as($this->a->leader)->post("/app/developers/webhooks/{$e->ulid}/test")->assertSessionHas('status', 'webhook-test-sent');
    $as($this->a->leader)->get('/app/developers')->assertInertia(fn ($p) => $p->has('webhooks', 1)->where('webhooks.0.lastDay.succeeded', 1)->missing('webhooks.0.secret')
        ->has('deliveries', 1)->where('deliveries.0.event_type', 'ping')->where('newSecret', null)->has('eventTypes', 15)->where('logs', []));
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->a->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $as($member)->get('/app/developers')->assertInertia(fn ($p) => $p->where('webhooks', [])->where('deliveries', [])->where('canManage', false));
    $as($member)->post("/app/developers/webhooks/{$e->ulid}/test")->assertForbidden();
    $as($member)->delete("/app/developers/webhooks/{$e->ulid}")->assertForbidden();
    $as($this->a->leader)->post("/app/developers/webhooks/{$e->ulid}/rotate")->assertSessionHas('status', 'webhook-rotated')->assertSessionHas('new_secret');
    $as($this->a->leader)->patch("/app/developers/webhooks/{$e->ulid}", ['active' => false])->assertSessionHas('status', 'webhook-updated');
    expect($e->fresh()->is_active)->toBeFalse();
    $as($this->a->leader)->delete("/app/developers/webhooks/{$e->ulid}")->assertSessionHas('status', 'webhook-deleted');
});

it('sends lead.received when a profile lead is confirmed', function () {
    Http::fake(['*' => Http::response('ok')]);
    ($this->hook)($this->b);
    $this->b->forceFill(['slug' => 'holder-solar', 'profile_public' => true])->save();
    $token = str_repeat('k', 40);
    DB::table('profile_leads')->insert(['team_id' => $this->b->id, 'referrer_name' => 'Pat', 'referrer_email' => 'pat@example.com', 'client_name' => 'Jo', 'need' => 'Quote', 'consent_text' => 'Agreed',
        'status' => 'unverified', 'verify_token_hash' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now()]);
    $this->get("/t/holder-solar/confirm/{$token}")->assertRedirect('/t/holder-solar');
    $got = received('https://hooks.example.com/t2t');
    expect($got)->toHaveCount(1)->and($got[0]['type'])->toBe('lead.received')->and($got[0]['body']['data']['object'])->toMatchArray(['object' => 'lead', 'status' => 'new', 'need' => 'Quote']);
});

it('bills the API plan for the days it is on, raises limits and keeps a 30-day request log', function () {
    $as = fn () => $this->actingAs($this->a->leader)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    $tok = ($this->token)($this->a);
    ($this->api)($tok)->getJson('/api/v1/me')->assertJsonPath('rate_limit_per_minute', 60);
    expect(DB::table('api_request_logs')->count())->toBe(0); // no log without the plan

    $this->travelTo(now()->parse('2027-03-11 10:00'));
    $as()->post('/app/billing/team/api-plan', ['on' => true])->assertSessionHas('status', 'api-plan-on');
    $as()->get('/app/billing')->assertInertia(fn ($p) => $p->where('accounts.0.apiPlan.on', true)->where('accounts.0.apiPlan.price', 29));
    $r = ($this->api)($tok)->getJson('/api/v1/me')->assertJsonPath('rate_limit_per_minute', 600);
    expect($r->headers->get('X-Request-Id'))->not->toBeEmpty();
    ($this->api)($tok)->getJson('/api/v1/referrals/NOPE')->assertNotFound();
    ($this->api)('t2t_live_'.str_repeat('x', 40))->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('X-Request-Id');
    $logs = DB::table('api_request_logs')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)->and($logs->pluck('status')->all())->toBe([200, 404])->and($logs[1]->path)->toBe('/api/v1/referrals/NOPE');
    $as()->get('/app/developers')->assertInertia(fn ($p) => $p->has('logs', 2)->where('logs.0.status', 404)->where('plan.on', true)->where('plan.endpointLimit', 10));

    // Off on the 20th: March bills the 11th to the 20th (10 of 31 days).
    $this->travelTo(now()->parse('2027-03-20 18:00'));
    $as()->post('/app/billing/team/api-plan', ['on' => false])->assertSessionHas('status', 'api-plan-off');
    ($this->api)($tok)->getJson('/api/v1/me')->assertJsonPath('rate_limit_per_minute', 60);
    $this->travelTo(now()->parse('2027-04-01 06:00'));
    app(Invoicing::class)->run('2027-03');
    $line = collect(json_decode((string) DB::table('invoices')->where(['owner_type' => 'team', 'owner_id' => $this->a->id, 'period' => '2027-03'])->value('lines'), true))->firstWhere('type', 'api_plan');
    expect($line)->toMatchArray(['days' => 10, 'amount_cents' => (int) round(2900 * 10 / 31)])->and($line['description'])->toContain('API plan');
    expect(RevenueTypes::classify($line))->toBe('api_plan');
    app(Invoicing::class)->run('2027-04');
    expect(collect(json_decode((string) DB::table('invoices')->where(['owner_type' => 'team', 'owner_id' => $this->a->id, 'period' => '2027-04'])->value('lines'), true))->where('type', 'api_plan'))->toHaveCount(0);

    // Logs and old deliveries go after 30 days.
    $this->travel(31)->days();
    Webhooks::prune();
    expect(DB::table('api_request_logs')->count())->toBe(0);
    expect(App\Domains\Billing\PricingRules::price('api_plan'))->toBe(29.0);
});

it('keeps the API sandbox out of invoices and the network’s figures', function () {
    $test = ($this->token)($this->a, 'test');
    ($this->api)($test)->getJson('/api/v1/me')->assertOk();
    $sg = Group::where('slug', 'api-sandbox-solar')->firstOrFail();
    $sample = collect(($this->api)($test)->getJson('/api/v1/groups/api-sandbox-solar')->json('sandbox_seats'))->firstWhere('yours', false);
    ($this->api)($test)->postJson('/api/v1/referrals', ['group' => 'api-sandbox-solar', 'city_id' => $sample['city_id'], 'answers' => ($this->answers)($sg), 'consent' => true])->assertCreated();
    $p = new Period(now()->subDay()->toImmutable(), now()->addDay()->toImmutable(), 'custom');
    expect(NetworkKpis::value('referrals', $p))->toBe(0)->and(NetworkKpis::value('seats_filled', $p))->toBe(2)->and(NetworkKpis::value('teams', $p))->toBe(2)->and(NetworkKpis::value('groups', $p))->toBe(1)
        ->and(NetworkKpis::top($p)['groups'])->toBe([]);
    $this->travel(70)->days();
    app(Invoicing::class)->run(now()->subMonth()->format('Y-m'));
    $billed = DB::table('invoices')->pluck('owner_id')->all();
    $sandboxTeams = DB::table('seats')->where('group_id', $sg->id)->pluck('primary_team_id')->all();
    expect(array_intersect($billed, $sandboxTeams))->toBe([]);
});
