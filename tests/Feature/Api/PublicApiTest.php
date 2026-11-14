<?php

use App\Domains\Api\ApiSpec;
use App\Domains\Api\ApiToken;
use App\Domains\Api\ApiTokens;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    $this->sms = fakeSms();
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
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    $this->a = $mk('Sender Solar');
    $this->b = $mk('Holder Solar');
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $this->token = fn (Team $t, string $mode = 'live', ?array $scopes = null) => ApiTokens::create($t, $t->leader, 'Test', $mode, $scopes ?? array_keys(ApiTokens::SCOPES))['plain'];
    $this->api = fn (string $token) => $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    $this->answers = function (Group $g, array $over = []): array {
        $a = [];
        foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
            $a[$f['key']] = match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text',
            };
        }

        return [...$a, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100', 'client_email' => 'tom@example.com', ...$over];
    };
});

it('lets a client create a referral and follow it to the end, with the same privacy rules as the app', function () {
    $send = ($this->token)($this->a);
    $recv = ($this->token)($this->b);
    $city = $this->cities[1]->id;
    ($this->api)($send)->getJson("/api/v1/groups/solar-installers-network/route?city_id={$city}")->assertOk()
        ->assertJson(['available' => true, 'receiver' => ['name' => 'Holder Solar']]);
    $body = ['group' => 'solar-installers-network', 'city_id' => $city, 'answers' => ($this->answers)($this->g), 'consent' => true];
    ($this->api)($send)->postJson('/api/v1/referrals', [...$body, 'consent' => false])->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('errors.consent.0', fn ($m) => str_contains($m, 'agree to be contacted'));

    $r = ($this->api)($send)->withHeader('Idempotency-Key', 'crm-lead-1')->postJson('/api/v1/referrals', $body)->assertCreated()
        ->assertJson(['direction' => 'sent', 'status' => 'sent', 'receiver' => ['name' => 'Holder Solar'], 'client' => ['name' => 'Tom Brennan', 'email' => 'tom@example.com']]);
    $id = $r->json('id');
    expect($r->headers->get('Location'))->toEndWith('/api/v1/referrals/'.$id);
    // A retry with the same key returns the same referral instead of a second one; the key can't be reused for something else.
    ($this->api)($send)->withHeader('Idempotency-Key', 'crm-lead-1')->postJson('/api/v1/referrals', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $id);
    ($this->api)($send)->withHeader('Idempotency-Key', 'crm-lead-1')->postJson('/api/v1/referrals', [...$body, 'notes' => 'other'])->assertStatus(422);
    expect(Referral::count())->toBe(1);
    // Sending the same client again without saying so is flagged as a likely duplicate.
    ($this->api)($send)->postJson('/api/v1/referrals', $body)->assertStatus(409)->assertJsonPath('title', 'Conflict');

    // The receiving team sees "Tom B." and no contact details until it accepts.
    ($this->api)($recv)->getJson('/api/v1/referrals/'.$id)->assertOk()->assertJson(['direction' => 'received', 'client' => ['name' => 'Tom B.', 'phone' => null, 'email' => null]]);
    ($this->api)($recv)->getJson('/api/v1/referrals?direction=received&status=sent')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('has_more', false);
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/accept")->assertOk()->assertJson(['status' => 'accepted', 'client' => ['name' => 'Tom Brennan', 'phone' => '+14355550100']]);
    $stages = ($this->api)($recv)->getJson('/api/v1/groups/solar-installers-network')->assertOk()->json('stages');
    $open = collect($stages)->firstWhere('kind', 'open')['key'];
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/stage", ['stage' => $open, 'note' => 'Called the client'])->assertOk()->assertJsonPath('stage.key', $open);
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/notes", ['note' => 'Site visit booked'])->assertCreated();
    $won = collect($stages)->firstWhere('kind', 'won')['key'];
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/stage", ['stage' => $won])->assertStatus(422)->assertJsonStructure(['errors' => ['closing_date']]);
    ($this->api)($recv)->postJson("/api/v1/referrals/{$id}/stage", ['stage' => $won, 'closing_date' => now()->toDateString(), 'closing_value' => 30000, 'earnings' => 3000])->assertOk()
        ->assertJsonPath('closing.value', 30000)->assertJsonPath('stage.kind', 'won');
    $events = collect(($this->api)($send)->getJson("/api/v1/referrals/{$id}/events")->assertOk()->json('data'))->pluck('type')->all();
    expect($events)->toContain('sent')->toContain('accepted')->toContain('stage')->toContain('update');
    // The sender can't act as the receiver.
    ($this->api)($send)->postJson("/api/v1/referrals/{$id}/decline", ['reason' => 'x'])->assertStatus(403);
});

it('authenticates by token, checks permissions, limits the rate and answers errors as problem details', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')->assertJsonPath('status', 401);
    $this->get('/api/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json'); // JSON even without Accept
    ($this->api)('t2t_live_'.str_repeat('x', 40))->getJson('/api/v1/me')->assertUnauthorized();
    $read = ($this->token)($this->a, 'live', ['referrals:read']);
    ($this->api)($read)->getJson('/api/v1/me')->assertOk()->assertJson(['mode' => 'live', 'team' => ['name' => 'Sender Solar'], 'rate_limit_per_minute' => 60, 'scopes' => ['referrals:read']]);
    ($this->api)($read)->postJson('/api/v1/referrals', [])->assertForbidden()->assertJsonPath('detail', 'This token doesn’t have the referrals:write permission.');
    ($this->api)($read)->getJson('/api/v1/referrals/NOPE')->assertNotFound()->assertJsonPath('title', 'Not found');
    $r = ($this->api)($read)->getJson('/api/v1/referrals')->assertOk();
    expect((int) $r->headers->get('X-RateLimit-Limit'))->toBe(60)->and((int) $r->headers->get('X-RateLimit-Remaining'))->toBeLessThan(60);
    foreach (range(1, 60) as $i) {
        ($this->api)($read)->getJson('/api/v1/me');
    }
    ($this->api)($read)->getJson('/api/v1/me')->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('title', 'Too many requests');
    expect(DB::table('api_tokens')->first())->token_hash->not->toContain('t2t_')->and(DB::table('api_tokens')->value('last_used_at'))->not->toBeNull();
});

it('stops a token when it is revoked, expires or its owner leaves the team; only leaders and assistants make them', function () {
    $t = ApiTokens::create($this->a, $this->a->leader, 'CRM', 'live', ['referrals:read']);
    ($this->api)($t['plain'])->getJson('/api/v1/me')->assertOk();
    ApiTokens::revoke($t['token'], $this->a->leader);
    ($this->api)($t['plain'])->getJson('/api/v1/me')->assertUnauthorized();
    $e = ApiTokens::create($this->a, $this->a->leader, 'Old', 'live', ['referrals:read'], now()->addDay()->toDateString());
    $this->travel(2)->days();
    ($this->api)($e['plain'])->getJson('/api/v1/me')->assertUnauthorized();
    $this->travelBack();
    $assistant = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->a->id, 'user_id' => $assistant->id, 'role' => 'assistant', 'created_at' => now(), 'updated_at' => now()]);
    $x = ApiTokens::create($this->a, $assistant, 'Assistant', 'live', ['referrals:read']);
    ($this->api)($x['plain'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('acting_as', $assistant->name);
    DB::table('team_members')->where('user_id', $assistant->id)->delete();
    ($this->api)($x['plain'])->getJson('/api/v1/me')->assertUnauthorized();
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->a->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => ApiTokens::create($this->a, $member, 'Nope', 'live', ['referrals:read']))->toThrow(Illuminate\Validation\ValidationException::class);

    // Through the portal: the token is shown once, and revoking is one click (both after confirming the password).
    $this->actingAs($this->a->leader)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/app/developers/tokens', ['name' => 'Zap', 'mode' => 'live', 'scopes' => ['referrals:read']])->assertSessionHas('status', 'api-token-created')
        ->assertSessionHas('new_token', fn ($n) => str_starts_with($n['plain'], 't2t_live_'));
    $this->actingAs($this->a->leader)->withSession(['auth.password_confirmed_at' => time()])->get('/app/developers')->assertOk()
        ->assertInertia(fn ($p) => $p->component('app/Developers')->has('tokens', 3)->where('tokens.0.name', 'Zap')->where('tokens', fn ($t) => collect($t)->pluck('state', 'name')->all() === ['Zap' => 'active', 'Assistant' => 'owner_left', 'Old' => 'active'])->missing('tokens.0.token_hash'));
    $id = ApiToken::query()->where('name', 'Zap')->value('ulid');
    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => time()])->delete('/app/developers/tokens/'.$id)->assertForbidden();
    $this->actingAs($this->a->leader)->withSession(['auth.password_confirmed_at' => time()])->delete('/app/developers/tokens/'.$id)->assertSessionHas('status', 'api-token-revoked');
    expect(DB::table('activity_log')->where('event', 'api-token-created')->count())->toBe(4);
});

it('gives test tokens a private sandbox where every step can be tried and nobody is contacted', function () {
    $test = ($this->token)($this->a, 'test');
    $me = ($this->api)($test)->getJson('/api/v1/me')->assertOk()->assertJsonPath('mode', 'test')->json();
    expect($me['team']['name'])->toBe('Sender Solar (sandbox)');
    $groups = ($this->api)($test)->getJson('/api/v1/groups')->assertOk()->json('data');
    expect($groups)->toHaveCount(1)->and($groups[0]['sandbox'])->toBeTrue()->and($groups[0]['slug'])->toBe('api-sandbox-solar');
    $sandbox = ($this->api)($test)->getJson('/api/v1/groups/api-sandbox-solar')->assertOk()->json();
    expect($sandbox['sandbox_seats'])->toHaveCount(4);
    // Test tokens can't reach real networks.
    ($this->api)($test)->postJson('/api/v1/referrals', ['group' => 'solar-installers-network', 'city_id' => $this->cities[1]->id, 'answers' => ($this->answers)($this->g), 'consent' => true])
        ->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'sandbox'));
    // Send to a sample team, then have it accept and win the referral.
    $sg = Group::where('slug', 'api-sandbox-solar')->firstOrFail();
    $sample = collect($sandbox['sandbox_seats'])->firstWhere('yours', false);
    expect(collect($sandbox['sandbox_seats'])->where('yours', true))->toHaveCount(1);
    $ref = ($this->api)($test)->postJson('/api/v1/referrals', ['group' => 'api-sandbox-solar', 'city_id' => $sample['city_id'], 'answers' => ($this->answers)($sg, ['client_email' => 'real.person@example.com']), 'consent' => true, 'text_client' => true])
        ->assertCreated()->assertJsonPath('sandbox', true)->json('id');
    ($this->api)($test)->postJson("/api/v1/sandbox/referrals/{$ref}/simulate", ['action' => 'accept'])->assertOk()->assertJsonPath('status', 'accepted');
    $won = collect($sandbox['stages'])->firstWhere('kind', 'won')['key'];
    ($this->api)($test)->postJson("/api/v1/sandbox/referrals/{$ref}/simulate", ['action' => 'stage', 'stage' => $won, 'closing_date' => now()->toDateString(), 'closing_value' => 1000, 'earnings' => 100])
        ->assertOk()->assertJsonPath('stage.kind', 'won');
    // And receive one from a sample team.
    ($this->api)($test)->postJson('/api/v1/sandbox/incoming-referrals')->assertCreated()->assertJson(['direction' => 'received', 'client' => ['name' => 'Sample C.']]);
    // Nobody real was contacted: no client email or text, and sandbox people are in-app only.
    Notification::assertNothingSentTo(new Illuminate\Notifications\AnonymousNotifiable);
    expect($this->sms->sent)->toBe([]);
    expect(Referral::where('group_id', $this->g->id)->count())->toBe(0);
    // The sandbox network is never public.
    $this->get('/groups/api-sandbox-solar')->assertNotFound();
    expect((string) $this->get('/sitemaps/directory.xml')->getContent())->not->toContain('api-sandbox');
    // Live tokens can't use the sandbox helpers.
    ($this->api)(($this->token)($this->a))->postJson('/api/v1/sandbox/incoming-referrals')->assertForbidden();
});

it('reads and updates leads, seats and the profile', function () {
    $tok = ($this->token)($this->b);
    DB::table('profile_leads')->insert([
        ['team_id' => $this->b->id, 'referrer_name' => 'Pat', 'referrer_email' => 'pat@example.com', 'client_name' => 'Jo', 'need' => 'Quote', 'consent_text' => 'Agreed', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()],
        ['team_id' => $this->b->id, 'referrer_name' => 'Unverified', 'referrer_email' => 'u@example.com', 'client_name' => 'X', 'need' => 'Q', 'consent_text' => 'Agreed', 'status' => 'unverified', 'created_at' => now(), 'updated_at' => now()],
        ['team_id' => $this->a->id, 'referrer_name' => 'Other team', 'referrer_email' => 'o@example.com', 'client_name' => 'Y', 'need' => 'Q', 'consent_text' => 'Agreed', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $leads = ($this->api)($tok)->getJson('/api/v1/leads')->assertOk()->json('data');
    expect($leads)->toHaveCount(1)->and($leads[0]['referrer']['name'])->toBe('Pat');
    ($this->api)($tok)->patchJson('/api/v1/leads/'.$leads[0]['id'], ['status' => 'contacted'])->assertOk()->assertJsonPath('status', 'contacted');
    ($this->api)($tok)->getJson('/api/v1/leads/'.DB::table('profile_leads')->where('referrer_name', 'Other team')->value('id'))->assertNotFound();
    ($this->api)($tok)->getJson('/api/v1/seats')->assertOk()->assertJsonPath('data.0.role', 'primary')->assertJsonPath('data.0.filled', true);
    ($this->api)($tok)->patchJson('/api/v1/profile', ['bio' => 'We install solar.', 'languages' => ['Spanish'], 'website' => 'not a url'])->assertStatus(422);
    ($this->api)($tok)->patchJson('/api/v1/profile', ['bio' => 'We install solar.', 'languages' => ['Spanish']])->assertOk()->assertJson(['bio' => 'We install solar.', 'languages' => ['Spanish'], 'name' => 'Holder Solar']);
    ($this->api)($tok)->getJson('/api/v1/cities?q='.substr($this->cities[0]->name, 0, 4))->assertOk()->assertJsonPath('data.0.id', $this->cities[0]->id);
});

it('documents exactly the routes that exist, in a valid OpenAPI description shown at /developers', function () {
    $spec = ApiSpec::document();
    $documented = [];
    foreach ($spec['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $m) {
            $documented[] = strtoupper($m).' /api/v1'.$path;
        }
    }
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && $r->uri() !== 'api/v1/openapi.json')
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' /'.$r->uri()))->values()->all();
    expect($documented)->toEqualCanonicalizing($routes);
    // Every $ref resolves.
    array_walk_recursive($spec, function ($v, $k) use ($spec) {
        if ($k === '$ref') {
            $parts = explode('/', substr((string) $v, 2));
            $node = $spec;
            foreach ($parts as $p) {
                expect($node)->toHaveKey($p);
                $node = $node[$p];
            }
        }
    });
    $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
    $this->get('/developers')->assertOk()->assertInertia(fn ($p) => $p->component('Developers')->has('spec.paths')->has('scopes', 9));
    expect((string) $this->get('/sitemaps/pages.xml')->getContent())->toContain('/developers</loc>');
});
