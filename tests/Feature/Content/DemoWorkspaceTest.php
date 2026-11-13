<?php

use App\Domains\Content\Demo\DemoWorkspace;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\NotificationCatalogue;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

const DEMO_TABLES = ['users', 'teams', 'seats', 'referrals', 'referral_events', 'referral_fees', 'credit_ledger', 'feed_posts', 'events', 'group_owners', 'team_licenses', 'seat_applications', 'trust_scores'];

beforeEach(function () {
    config(['seo.indexable' => true]);
    Mail::fake();
    $this->counts = fn () => collect(DEMO_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
});

it('DONE WHEN: a fresh install with --demo shows a finished public site and populated demo dashboards, with no manual setup', function () {
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations'), '--demo' => true]);
    foreach (['/how-it-works', '/pricing', '/about', '/faq', '/contact'] as $url) {
        $this->get($url)->assertOk();
    }
    $team = User::query()->where('email', 'demo2@'.DemoWorkspace::DOMAIN)->firstOrFail();
    $cards = collect($this->actingAs($team)->get('/app/dashboard')->assertOk()->viewData('page')['props']['cards'])->keyBy('key');
    expect($cards['received']['value'] + $cards['sent']['value'])->toBeGreaterThan(0);
    $all = collect(User::query()->where('email', 'like', '%@'.DemoWorkspace::DOMAIN)->get())->map(fn (User $u) => Team::of($u))->filter();
    $won = $all->sum(fn (Team $t) => DB::table('referrals')->where('receiver_team_id', $t->id)->whereIn('close_status', ['confirmed', 'auto_confirmed'])->count());
    expect($won)->toBeGreaterThan(0)->and(DB::table('referral_fees')->count())->toBeGreaterThan(0)->and(DB::table('referral_fees')->where('status', 'received')->count())->toBeGreaterThan(0)
        ->and(DB::table('referrals')->where('status', 'declined')->count())->toBeGreaterThan(0)->and(DB::table('trust_scores')->where('status', 'scored')->count())->toBeGreaterThan(0)
        ->and(DB::table('feed_posts')->count())->toBeGreaterThan(0)->and(DB::table('events')->count())->toBeGreaterThan(0);
    $owner = User::query()->where('email', 'demo1@'.DemoWorkspace::DOMAIN)->firstOrFail();
    $this->flushSession(); // a new sign-in (the session guard ties a session to one user's password)
    $oc = collect($this->actingAs($owner)->get('/app/group/solar-installers-network/dashboard')->assertOk()->viewData('page')['props']['cards'])->keyBy('key');
    expect($oc['seats']['value'])->toBeGreaterThan(0)->and($oc['referrals']['value'])->toBeGreaterThan(0);
    $this->flushSession();
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $nc = collect($this->actingAs($admin)->get('/admin/network')->assertOk()->viewData('page')['props']['cards'])->keyBy('key');
    expect($nc['teams']['value'])->toBeGreaterThan(0);
    expect(DB::table('analytics_events')->count())->toBeGreaterThan(0);
});

it('is safe: refused in production, sends nothing, and uses only addresses that can never be reached', function () {
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    $this->app['env'] = 'production';
    expect(Artisan::call('t2t:demo'))->toBe(1)->and(DemoWorkspace::exists())->toBeFalse();
    $this->app['env'] = 'testing';
    Artisan::call('t2t:demo');
    Mail::assertNothingSent();
    $demoUsers = DB::table('users')->pluck('email');
    expect($demoUsers->every(fn ($e) => str_ends_with((string) $e, '@'.DemoWorkspace::DOMAIN)))->toBeTrue()
        ->and(DB::table('referrals')->pluck('client_phone')->every(fn ($p) => (bool) preg_match('/^\+?1?4355550(1\d\d)$/', preg_replace('/\D/', '', (string) $p))))->toBeTrue();
    // Demo users get in-app notifications only.
    Notification::fake();
    $u = User::query()->where('email', 'demo2@'.DemoWorkspace::DOMAIN)->firstOrFail();
    app(Notifier::class)->send($u, 'network.checkin', ['headline' => 'Hi', 'body' => 'Checking in']);
    Notification::assertSentTo($u, InAppNotification::class, fn ($n, array $channels) => $channels === ['database']);
});

it('resets exactly the demo: real records stay and seats that existed before are released, not deleted', function () {
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    Notification::fake();
    // A real member and referral, before the demo.
    $g = Group::where('slug', 'movers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    [$a, $b] = [Team::forLeader(User::factory()->create()), Team::forLeader(User::factory()->create())];
    app(Seats::class)->approve(app(Seats::class)->apply($g, $a, $cities[2], 'L', null), null);
    app(Seats::class)->approve(app(Seats::class)->apply($g, $b, $cities[1], 'L', null), null);
    $ans = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $real = app(Referrals::class)->send($a->leader, $g, $cities[1], [...$ans, 'client_name' => 'Real Client', 'client_phone' => '+14355550100'], true, null, true)['referral'];
    // A seat that exists but is open, in a city the demo will use.
    $solar = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $openSeat = DB::table('seats')->insertGetId(['group_id' => $solar->id, 'territory_type' => 'city', 'territory_id' => $cities[0]->id, 'territory_name' => $cities[0]->name, 'state_code' => 'UT', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $before = ($this->counts)();
    Artisan::call('t2t:demo');
    expect(($this->counts)()['referrals'])->toBeGreaterThan($before['referrals']);
    Artisan::call('t2t:demo', ['action' => 'reset']);
    expect(($this->counts)())->toBe($before)
        ->and(DB::table('referrals')->where('id', $real->id)->exists())->toBeTrue()
        ->and(DB::table('seats')->where('id', $openSeat)->first())->primary_team_id->toBeNull()
        ->and(DemoWorkspace::exists())->toBeFalse();
});

it('[7] keeps every notification complete: email text for all, SMS wording for those that text, within two segments', function () {
    foreach (NotificationCatalogue::TYPES as $key => $t) {
        expect(trim((string) $t['title']))->not->toBe('', "{$key} title")->and(trim((string) $t['body']))->not->toBe('', "{$key} body");
        if (in_array('sms', $t['channels'], true)) {
            expect(trim((string) $t['sms']))->not->toBe('', "{$key} sms")->and(mb_strlen((string) $t['sms']))->toBeLessThanOrEqual(300, "{$key} sms length");
        }
    }
});
