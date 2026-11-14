<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Integrations\CrmSync;
use App\Domains\Integrations\Integration;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

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
    // The platform's Follow Up Boss registration (Admin → Integrations).
    app(Settings::class)->set('integrations.followupboss.system', 'TeamToTeamReferrals');
    app(Settings::class)->set('integrations.followupboss.system_key', 'sys-key-123', true);
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->cities = City::where('is_enabled', true)->orderByDesc('population')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
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
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    $this->stages = app(IndustryTemplates::class)->resolve('stages', $this->g->industry, $this->g->id)['body'];
});

/** A Follow Up Boss account that answers like the real one, keeping what it was sent. */
function fakeFollowUpBoss(array &$state): void
{
    $state += ['people' => [], 'notes' => [], 'stage' => [], 'down' => false, 'refuse' => false];
    Http::fake(function (HttpRequest $r) use (&$state) {
        if ($state['refuse']) {
            return Http::response(['errorMessage' => 'Invalid API key'], 401);
        }
        if ($state['down']) {
            return Http::response('busy', 503);
        }
        $path = (string) parse_url($r->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/v1/me' => Http::response(['id' => 1, 'name' => 'Holly Holder', 'account' => ['name' => 'Holder Solar Group']]),
            $path === '/v1/users' => Http::response(['users' => [['id' => 7, 'name' => 'Ana Agent'], ['id' => 8, 'name' => 'Ben Agent']]]),
            $path === '/v1/stages' => Http::response(['stages' => [['name' => 'Lead'], ['name' => 'Contacted'], ['name' => 'Active Client'], ['name' => 'Closed'], ['name' => 'Trash']]]),
            $path === '/v1/events' => (function () use (&$state, $r) {
                $email = $r['person']['emails'][0]['value'] ?? '';
                $id = array_search($email, array_column($state['people'], 'email', 'id'), true) ?: 500 + count($state['people']);
                $state['people'][$id] = ['id' => $id, 'email' => $email, 'body' => $r->data()];
                $state['stage'][$id] ??= 'Lead';

                return Http::response(['id' => 99, 'personId' => $id], 201);
            })(),
            $path === '/v1/notes' => (function () use (&$state, $r) {
                $state['notes'][] = $r->data();

                return Http::response(['id' => count($state['notes'])], 201);
            })(),
            str_starts_with($path, '/v1/people/') => $r->method() === 'GET'
                ? Http::response(['id' => (int) basename($path), 'stage' => $state['stage'][(int) basename($path)] ?? 'Lead'])
                : Http::response(['id' => (int) basename($path)]),
            default => Http::response('not found', 404),
        };
    });
}

it('puts an accepted referral in the member’s Follow Up Boss within a minute (the 45 "done when")', function () {
    $fub = [];
    fakeFollowUpBoss($fub);
    ($this->as)($this->b->leader)->post('/app/integrations', ['provider' => 'followupboss', 'api_key' => 'fub-key-holder-123'])->assertSessionHas('status', 'integration-connected');
    $i = Integration::firstOrFail();
    expect($i)->account_name->toBe('Holder Solar Group')->status->toBe('active')->and($i->remote_users)->toHaveCount(2)->and($i->getRawOriginal('credentials'))->not->toContain('fub-key-holder');

    $ref = ($this->send)();
    expect($fub['people'])->toBe([]); // not before the receiving team accepts
    $t0 = now();
    app(Referrals::class)->accept($ref, $this->b->leader);

    $sent = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/events'));
    expect($sent)->toHaveCount(1);
    [$req] = $sent->first();
    expect($req->header('X-System')[0])->toBe('TeamToTeamReferrals')->and($req->header('X-System-Key')[0])->toBe('sys-key-123')
        ->and($req->header('Authorization')[0])->toBe('Basic '.base64_encode('fub-key-holder-123:'))
        ->and($req['source'])->toBe('Team to Team Referrals')->and($req['type'])->toBe('General Inquiry')
        ->and($req['person'])->toMatchArray(['firstName' => 'Tom', 'lastName' => 'Brennan', 'emails' => [['value' => 'tom@example.com']], 'phones' => [['value' => '+14355550100']], 'tags' => ['Team to Team Referrals', $this->g->name]])
        ->and($req['message'])->toContain('Referral from Sender Solar')->toContain('/app/referrals/'.$ref->ulid);
    $log = DB::table('sync_logs')->where(['action' => 'contact'])->first();
    expect($log->status)->toBe('ok')->and($log->remote_id)->toBe('500')->and(now()->parse($log->updated_at)->diffInSeconds($t0, true))->toBeLessThan(60);
    expect(DB::table('integration_mappings')->where(['kind' => 'record', 'referral_id' => $ref->id])->value('remote_value'))->toBe('500');

    // Updates posted on the referral become notes on the contact.
    app(Pipeline::class)->note($ref->fresh(), $this->b->leader, 'Client prefers mornings');
    expect($fub['notes'])->toHaveCount(1)->and($fub['notes'][0])->toMatchArray(['personId' => 500, 'body' => 'Update from Holder Solar: Client prefers mornings']);
});

it('assigns to the chosen agent and sends open accepted referrals when connecting', function () {
    $fub = [];
    fakeFollowUpBoss($fub);
    $ref = ($this->send)();
    app(Referrals::class)->accept($ref, $this->b->leader);
    expect($fub['people'])->toBe([]);
    $i = CrmSync::connect($this->b, $this->b->leader, 'followupboss', 'fub-key-holder-123', ['backfill' => true]);
    expect($fub['people'])->toHaveCount(1);
    ($this->as)($this->b->leader)->patch("/app/integrations/{$i->ulid}", ['assign_user_id' => '99'])->assertSessionHasErrors('assign_user_id');
    ($this->as)($this->b->leader)->patch("/app/integrations/{$i->ulid}", ['assign_user_id' => '8'])->assertSessionHas('status', 'integration-updated');
    $ref2 = ($this->send)(['client_email' => 'jo@example.com', 'client_phone' => '+14355550199', 'client_name' => 'Jo Lee']);
    app(Referrals::class)->accept($ref2, $this->b->leader);
    expect(end($fub['people'])['body']['person']['assignedUserId'])->toBe(8);
});

it('follows stage changes made in the CRM', function () {
    $fub = [];
    fakeFollowUpBoss($fub);
    $i = CrmSync::connect($this->b, $this->b->leader, 'followupboss', 'fub-key-holder-123');
    $open = collect($this->stages)->where('kind', 'open')->values();
    $won = collect($this->stages)->firstWhere('kind', 'won');
    $lost = collect($this->stages)->firstWhere('kind', 'lost');
    ($this->as)($this->b->leader)->patch("/app/integrations/{$i->ulid}", ['map' => ['Active Client' => $open[1]['key'], 'Closed' => $won['key'], 'Trash' => $lost['key'], 'Contacted' => 'not-a-stage']])->assertSessionHas('status', 'integration-updated');
    expect(CrmSync::stageMap($i))->toMatchArray(['Active Client' => $open[1]['key'], 'Closed' => $won['key'], 'Trash' => $lost['key'], 'Contacted' => '']);
    $ref = ($this->send)();
    app(Referrals::class)->accept($ref, $this->b->leader);
    CrmSync::pollStages(); // first look: records "Lead"
    expect($ref->fresh()->stage)->toBe($open[0]['key']);

    $fub['stage'][500] = 'Active Client';
    CrmSync::pollStages();
    expect($ref->fresh()->stage)->toBe($open[1]['key']);
    expect(DB::table('referral_events')->where(['referral_id' => $ref->id, 'event' => 'stage'])->orderByDesc('id')->value('meta'))->toContain('Active Client');

    $fub['stage'][500] = 'Contacted'; // not mapped: left as is
    CrmSync::pollStages();
    expect($ref->fresh()->stage)->toBe($open[1]['key']);

    $fub['stage'][500] = 'Closed'; // won needs the closing details: the team is asked
    CrmSync::pollStages();
    expect($ref->fresh()->stage)->toBe($open[1]['key']);
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'integrations.attention' && str_contains($n->title, 'Record the close'));

    $fub['stage'][500] = 'Trash';
    CrmSync::pollStages();
    expect($ref->fresh())->stage->toBe($lost['key'])->lost_reason->toContain('Follow Up Boss')->closed_at->not->toBeNull();
    CrmSync::pollStages(); // closed referrals are no longer checked
    expect(Http::recorded(fn (HttpRequest $r) => $r->method() === 'GET' && str_contains($r->url(), '/v1/people/')))->toHaveCount(5);
});

it('retries when the CRM is down, and pauses with a notice when it refuses the key', function () {
    $fub = [];
    fakeFollowUpBoss($fub);
    $i = CrmSync::connect($this->b, $this->b->leader, 'followupboss', 'fub-key-holder-123');
    $fub['down'] = true;
    $ref = ($this->send)();
    app(Referrals::class)->accept($ref, $this->b->leader);
    $log = DB::table('sync_logs')->where('action', 'contact')->first();
    expect($log)->status->toBe('retrying')->attempts->toBe(1)->message->toContain('503');
    $fub['down'] = false;
    $this->travel(61)->seconds();
    CrmSync::retryDue();
    expect(DB::table('sync_logs')->where('id', $log->id)->value('status'))->toBe('ok')->and($fub['people'])->toHaveCount(1);

    $fub['refuse'] = true;
    app(Pipeline::class)->note($ref->fresh(), $this->b->leader, 'Any news?');
    expect($i->fresh())->status->toBe('error')->last_error->toContain('refused');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'integrations.attention' && str_contains($n->title, 'needs a new API key'));
    app(Pipeline::class)->note($ref->fresh(), $this->b->leader, 'Hello?');
    expect(DB::table('sync_logs')->where('action', 'note')->pluck('status')->all())->toBe(['failed', 'failed']);
    expect(Notification::sent($this->b->leader, InAppNotification::class)->filter(fn ($n) => $n->type === 'integrations.attention')->count())->toBe(1); // told once

    // A working key: connected again, and what failed is sent.
    $fub['refuse'] = false;
    ($this->as)($this->b->leader)->post('/app/integrations', ['provider' => 'followupboss', 'api_key' => 'fub-key-holder-NEW'])->assertSessionHas('status', 'integration-connected');
    ($this->as)($this->b->leader)->post("/app/integrations/{$i->ulid}/retry")->assertSessionHas('status', 'integration-retrying');
    expect($i->fresh()->status)->toBe('active')->and($fub['notes'])->toHaveCount(2);
});

it('creates or updates Lofty leads by email, with notes and stages', function () {
    $calls = [];
    Http::fake(function (HttpRequest $r) use (&$calls) {
        $calls[] = $r->method().' '.parse_url($r->url(), PHP_URL_PATH).(str_contains($r->url(), 'email=') ? '?email' : '');

        return match (true) {
            str_contains($r->url(), '/leads?') && str_contains($r->url(), 'email=') => Http::response(['leads' => str_contains($r->url(), 'old%40example.com') ? [['leadId' => 777]] : []]),
            str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/v1.0/leads') && $r->method() === 'GET' => Http::response(['leads' => []]),
            $r->method() === 'POST' && str_ends_with($r->url(), '/v1.0/leads') => Http::response(['leadId' => 888]),
            $r->method() === 'PUT' => Http::response(['leadId' => 777]),
            str_ends_with($r->url(), '/v1.0/notes') => Http::response(['noteId' => 1]),
            default => Http::response(['stage' => 'Nurture']),
        };
    });
    $i = CrmSync::connect($this->b, $this->b->leader, 'lofty', 'lofty-key-12345');
    expect(Http::recorded()[0][0]->header('Authorization')[0])->toBe('token lofty-key-12345');
    app(Referrals::class)->accept(($this->send)(), $this->b->leader);
    app(Referrals::class)->accept(($this->send)(['client_email' => 'old@example.com', 'client_name' => 'Old Client', 'client_phone' => '+14355550111']), $this->b->leader);
    expect($calls)->toContain('POST /v1.0/leads')->toContain('PUT /v1.0/leads/777');
    $post = collect(Http::recorded())->first(fn ($x) => $x[0]->method() === 'POST' && str_ends_with($x[0]->url(), '/v1.0/leads'))[0];
    expect($post['firstName'])->toBe('Tom')->and($post['emails'])->toBe(['tom@example.com'])->and($post['tags'])->toBe(['Team to Team Referrals', $this->g->name]);
    expect(DB::table('integration_mappings')->where('kind', 'record')->pluck('remote_value')->sort()->values()->all())->toBe(['777', '888']);
    expect($i->fresh()->status)->toBe('active');
});

it('creates BoldTrail contacts with tags and notes, and reads their status', function () {
    $sent = [];
    Http::fake(function (HttpRequest $r) use (&$sent) {
        $sent[] = $r->method().' '.parse_url($r->url(), PHP_URL_PATH);

        return match (true) {
            $r->method() === 'POST' && str_ends_with($r->url(), '/contact') => Http::response(['id' => 4321]),
            $r->method() === 'GET' && str_contains($r->url(), '/contact/4321') => Http::response(['id' => 4321, 'status' => 4]),
            default => Http::response(['data' => []]),
        };
    });
    $i = CrmSync::connect($this->b, $this->b->leader, 'boldtrail', 'bt-token-12345', ['sync_notes' => true]);
    expect($i->remote_stages)->toContain('Active')->toContain('Closed');
    expect(Http::recorded()[0][0]->header('Authorization')[0])->toBe('Bearer bt-token-12345');
    $ref = ($this->send)();
    app(Referrals::class)->accept($ref, $this->b->leader);
    expect($sent)->toContain('POST /v2/public/contact')->toContain('PUT /v2/public/contact/4321/tag');
    $create = collect(Http::recorded())->first(fn ($x) => $x[0]->method() === 'POST' && str_ends_with($x[0]->url(), '/contact'))[0];
    expect($create->data())->toMatchArray(['first_name' => 'Tom', 'last_name' => 'Brennan', 'email' => 'tom@example.com', 'cell_phone_1' => '+14355550100', 'status' => 0]);
    app(Pipeline::class)->note($ref->fresh(), $this->b->leader, 'Called');
    expect($sent)->toContain('POST /v2/public/contact/4321/action/note');
    CrmSync::pollStages();
    expect(json_decode((string) DB::table('integration_mappings')->where('kind', 'record')->value('meta'), true)['stage'])->toBe('Active');
});

it('sends sent referrals only when asked, and never sandbox ones', function () {
    $fub = [];
    fakeFollowUpBoss($fub);
    $i = CrmSync::connect($this->a, $this->a->leader, 'followupboss', 'fub-key-sender-123');
    ($this->send)();
    expect($fub['people'])->toBe([]);
    ($this->as)($this->a->leader)->patch("/app/integrations/{$i->ulid}", ['sync_sent' => true]);
    ($this->send)(['client_email' => 'jo@example.com', 'client_phone' => '+14355550199', 'client_name' => 'Jo Lee']);
    expect($fub['people'])->toHaveCount(1)->and(array_values($fub['people'])[0]['body']['person']['tags'])->toContain('Referred out')
        ->and(array_values($fub['people'])[0]['body']['message'])->toContain('Referred out to Holder Solar');
    // The API sandbox never reaches a real CRM.
    App\Domains\Api\Sandbox::twin($this->a);
    $sg = Group::where('is_sandbox', true)->firstOrFail();
    Referral::query()->where('group_id', $this->g->id)->update(['group_id' => $sg->id]);
    CrmSync::onTimeline(Referral::query()->latest('id')->firstOrFail(), 'sent');
    expect($fub['people'])->toHaveCount(1);
});

it('lets only leaders and assistants manage connections, and admins switch CRMs off', function () {
    $fub = [];
    fakeFollowUpBoss($fub);
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->b->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    ($this->as)($member)->post('/app/integrations', ['provider' => 'followupboss', 'api_key' => 'fub-key-holder-123'])->assertForbidden();
    $fub['refuse'] = true;
    ($this->as)($this->b->leader)->post('/app/integrations', ['provider' => 'followupboss', 'api_key' => 'wrong-key-123'])->assertSessionHasErrors(['api_key']);
    $fub['refuse'] = false;
    $i = CrmSync::connect($this->b, $this->b->leader, 'followupboss', 'fub-key-holder-123');
    ($this->as)($member)->get('/app/integrations')->assertOk()->assertInertia(fn ($p) => $p->component('app/Integrations')->where('canManage', false)->where('logs', [])->where('connections.0.users', [])->where('connections.0.status', 'active'));
    ($this->as)($this->b->leader)->get('/app/integrations')->assertInertia(fn ($p) => $p->where('canManage', true)->has('connections.0.users', 2)->has('providers', 3)->missing('connections.0.credentials'));
    ($this->as)($member)->delete("/app/integrations/{$i->ulid}")->assertForbidden();
    ($this->as)($this->a->leader)->delete("/app/integrations/{$i->ulid}")->assertNotFound();
    ($this->as)($this->b->leader)->post("/app/integrations/{$i->ulid}/pause", ['paused' => true])->assertSessionHas('status', 'integration-paused');
    app(Referrals::class)->accept(($this->send)(), $this->b->leader);
    expect($fub['people'])->toBe([]);
    ($this->as)($this->b->leader)->post("/app/integrations/{$i->ulid}/pause", ['paused' => false]);
    expect($fub['people'])->toHaveCount(1); // what waited is sent on resume

    // Admin: Follow Up Boss needs the platform's registration; any CRM can be switched off.
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $this->actingAs($admin)->get('/admin/integrations')->assertInertia(fn ($p) => $p->component('admin/Integrations')->where('fub.key', '••••••••-123')->where('providers.0.connections', 1));
    $this->actingAs($admin)->post('/admin/integrations', ['enabled' => ['followupboss' => false], 'fub_system_key' => ''])->assertSessionHas('status', 'integrations-saved');
    expect(app(Settings::class)->get('integrations.followupboss.system_key'))->toBe('sys-key-123');
    ($this->as)($this->b->leader)->get('/app/integrations')->assertInertia(fn ($p) => $p->where('providers.0.available', false));
    app(Referrals::class)->accept(($this->send)(['client_email' => 'jo@example.com', 'client_phone' => '+14355550199', 'client_name' => 'Jo Lee']), $this->b->leader);
    expect($fub['people'])->toHaveCount(1);
    $this->actingAs($this->b->leader)->get('/admin/integrations')->assertForbidden();
    ($this->as)($this->b->leader)->delete("/app/integrations/{$i->ulid}")->assertSessionHas('status', 'integration-disconnected');
    expect(DB::table('activity_log')->where('event', 'like', 'integration-%')->pluck('event')->all())->toContain('integration-connected', 'integration-paused', 'integration-resumed', 'integration-disconnected');
});
