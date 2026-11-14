<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Consumer\ConsumerRequests;
use App\Domains\Referrals\Dashboard\Period;
use App\Domains\Referrals\Dashboard\TeamKpis;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    $this->sms = fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    Cache::flush();
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->city = fn (string $slug) => City::where('slug', $slug)->firstOrFail();
    $sv = app(Seats::class);
    $this->mk = function (string $name, string $city, ?Group $g = null) use ($sv) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $sv->approve($sv->apply($g ?? $this->g, $t, ($this->city)($city), 'L', null), null);

        return $t;
    };
    $this->houston = ($this->mk)('Hurricane Sun', 'houston');
    $this->austin = ($this->mk)('Lone Star Solar', 'austin');
    // A visitor fills the form: the industry's questions, how and when, their details and consent.
    $this->form = function (array $over = []) {
        $answers = collect(app(ConsumerRequests::class)->questions($this->g))->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();

        return [...['name' => 'Maria Lopez', 'phone' => '(713) 555-0144', 'email' => 'maria@example.com', 'contact_method' => 'text', 'best_time' => 'evening', 'timing' => 'now',
            'answers' => $answers, 'consent' => true, 'started' => encrypt(now()->subSeconds(30)->timestamp)], ...$over];
    };
    $this->ask = fn (string $city = 'houston', array $over = []) => $this->post('/request/'.$this->g->slug.'/'.($this->city)($city)->id, ($this->form)($over));
});

it('answers “how do I find a trusted pro here” on city pages, and shows the request form only where the tier allows', function () {
    $p = $this->get('/industries/solar/tx/houston')->assertOk()->viewData('page')['props'];
    expect($p['howTo'])->toContain('your request goes to Hurricane Sun alone (or their backup), never sold to several')
        ->and($p['request'])->toMatchArray(['group' => $this->g->slug, 'cityId' => ($this->city)('houston')->id, 'pro' => 'Hurricane Sun', 'responseHours' => null]);
    $schema = collect($p['seo']['schema']);
    expect($schema->firstWhere('@type', 'FAQPage')['mainEntity'][0]['name'])->toBe('How do I find a trusted installer in Houston, TX?')
        ->and($schema->firstWhere('@type', 'Service')['areaServed']['name'])->toBe('Houston, Texas')
        ->and($schema->firstWhere('@type', 'Service')['provider']['name'])->toBe('Hurricane Sun');
    // Directory (Tier C) industries: listing and contact only.
    $pi = tap(Group::query()->whereHas('industry', fn ($q) => $q->where('slug', 'personal-injury'))->firstOrFail())->update(['status' => 'live']);
    $lawyer = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Bayou Injury Law']);
    app(Seats::class)->approve(app(Seats::class)->apply($pi, $lawyer, ($this->city)('houston'), 'BAR-1', null), null);
    $law = $this->get('/industries/personal-injury/tx/houston')->assertOk()->viewData('page')['props'];
    expect($law['request'])->toBeNull()->and($law['howTo'])->toContain('This is a directory');
    $this->post('/request/'.$pi->slug.'/'.($this->city)('houston')->id, ($this->form)())->assertSessionHasErrors('group');
    // The cached page gets its form token from an uncached call.
    expect($this->getJson('/request/token')->assertOk()->json('started'))->not->toBeEmpty();
});

it('takes a request from the Houston page to the Houston seat holder after a phone check, with no referral fee or agreement', function () {
    ($this->ask)()->assertRedirect();
    $req = DB::table('consumer_requests')->first();
    expect($req->status)->toBe('unverified')->and($req->phone)->toBe('+17135550144')->and($req->source_path)->toBeNull();
    $this->get('/request/'.$req->ulid.'/verify')->assertOk()->assertInertia(fn ($p) => $p->where('phone', fn ($v) => str_ends_with((string) $v, '0144')));
    $this->post('/request/'.$req->ulid.'/verify', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/request/'.$req->ulid.'/verify', ['code' => $this->sms->lastCode()])->assertRedirect('/request/'.$req->ulid.'/done');
    $ref = Referral::query()->where('source', 'consumer')->firstOrFail();
    expect($ref->receiver_team_id)->toBe($this->houston->id)->and($ref->routed_to)->toBe('holder')->and($ref->consent_method)->toBe('client_confirmed')
        ->and($ref->answers['contact_by'])->toBe('Text message')->and($ref->answers['how_soon'])->toBe('As soon as possible')
        ->and(DB::table('consumer_requests')->value('status'))->toBe('routed');
    expect(end($this->sms->sent)['message'])->toContain('went to Hurricane Sun')->toContain('/r/'.$ref->client_token);
    $this->get('/request/'.$req->ulid.'/done')->assertInertia(fn ($p) => $p->where('pro.name', 'Hurricane Sun')->where('portal', url('/r/'.$ref->client_token)));
    // Accepted: no referral agreement, no member fee, no credits; no per-request charge while the setting is 0.
    app(Referrals::class)->accept($ref->fresh(), $this->houston->leader);
    expect(DB::table('referral_agreements')->where('referral_id', $ref->id)->exists())->toBeFalse()
        ->and(DB::table('credit_ledger')->where('referral_id', $ref->id)->exists())->toBeFalse()
        ->and(App\Domains\Referrals\Fees::forReferral($ref->fresh()))->toBeNull()
        ->and(DB::table('consumer_requests')->value('fee_cents'))->toBeNull();
    // Counted on the team's dashboard as a website request.
    expect(TeamKpis::value('website', $this->houston, Period::from('last_30', '', '')))->toBe(1);
});

it('records a per-request fee on acceptance only when a super admin has set one', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::STAFF);
    $admin->givePermissionTo('billing.manage');
    $this->actingAs($admin)->post('/admin/billing/settings', ['consumer_request_fee' => 25])->assertForbidden();
    auth()->logout();
    app(Settings::class)->set('billing.consumer_request_fee', '25');
    ($this->ask)();
    $req = DB::table('consumer_requests')->first();
    $this->post('/request/'.$req->ulid.'/verify', ['code' => $this->sms->lastCode()]);
    app(Referrals::class)->accept(Referral::query()->where('source', 'consumer')->firstOrFail(), $this->houston->leader);
    expect(DB::table('consumer_requests')->value('fee_cents'))->toBe(2500);
});

it('shows a request’s pages only to the browser that made it, and never sends the same person twice', function () {
    ($this->ask)();
    $req = DB::table('consumer_requests')->first();
    $this->flushSession();
    $this->get('/request/'.$req->ulid.'/verify')->assertNotFound();
    $this->withSession(['consumer_request' => $req->ulid])->post('/request/'.$req->ulid.'/verify', ['code' => $this->sms->lastCode()]);
    $sent = count($this->sms->sent);
    ($this->ask)('houston', ['email' => 'other@example.com'])->assertRedirect();
    $dup = DB::table('consumer_requests')->orderByDesc('id')->first();
    expect($dup->status)->toBe('duplicate')->and(count($this->sms->sent))->toBe($sent); // no code, nothing sent
    $this->get('/request/'.$dup->ulid.'/done')->assertInertia(fn ($p) => $p->where('status', 'duplicate'));
});

it('passes to the open board when nobody in the seat takes it, where a team in the same state can take it once', function () {
    ($this->ask)();
    $req = DB::table('consumer_requests')->first();
    $this->post('/request/'.$req->ulid.'/verify', ['code' => $this->sms->lastCode()]);
    $ref = Referral::query()->where('source', 'consumer')->firstOrFail();
    app(Referrals::class)->decline($ref, $this->houston->leader, 'Booked solid');
    expect(DB::table('consumer_requests')->value('status'))->toBe('board')->and(end($this->sms->sent)['message'])->toContain('We’re finding a verified pro');
    // Austin (same network, same state) sees it; Houston, who declined, doesn't; a team elsewhere doesn't.
    $this->actingAs($this->austin->leader)->get('/app/requests')->assertInertia(fn ($p) => $p->where('requests.0.client', 'Maria L.')->where('requests.0.city', 'Houston, TX'));
    $this->actingAs($this->houston->leader)->get('/app/requests')->assertInertia(fn ($p) => $p->where('requests', []));
    $utah = ($this->mk)('Red Rock Solar', 'saint-george');
    $this->actingAs($utah->leader)->get('/app/requests')->assertInertia(fn ($p) => $p->where('requests', []));
    $this->actingAs($this->austin->leader)->post('/app/requests/'.$req->ulid.'/take')->assertRedirect();
    $taken = Referral::query()->where('source', 'consumer')->where('receiver_team_id', $this->austin->id)->firstOrFail();
    expect($taken->status)->toBe('accepted')->and($taken->routed_to)->toBe('board')
        ->and(DB::table('consumer_requests')->value('status'))->toBe('claimed')
        ->and(end($this->sms->sent)['message'])->toContain('Lone Star Solar will contact you');
    $this->actingAs($this->austin->leader)->post('/app/requests/'.$req->ulid.'/take')->assertSessionHasErrors('request');
});

it('sends visitors from the find-a-pro page to the right city page', function () {
    $this->get('/find-a-pro')->assertOk()->assertInertia(fn ($p) => $p->component('FindAPro')->where('industries', fn ($i) => collect($i)->pluck('slug')->contains('solar')));
    $this->post('/find-a-pro', ['industry' => 'solar', 'city_id' => ($this->city)('houston')->id])->assertRedirect('/industries/solar/tx/houston#request');
});

it('words the industry questions for the person asking', function () {
    expect(ConsumerRequests::forClient('Do they own the home?'))->toBe('Do you own the home?')
        ->and(ConsumerRequests::forClient('What is the client’s budget?'))->toBe('What is your budget?')
        ->and(ConsumerRequests::forClient('Is the client ready?'))->toBe('Are you ready?')
        ->and(collect(app(ConsumerRequests::class)->questions($this->g))->pluck('label')->implode(' '))->not->toMatch('/\bthey\b|\bclient\b/i');
});
