<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Analytics\AnalyticsStore;
use App\Domains\Platform\Analytics\MySqlStore;
use App\Domains\Platform\Analytics\Shipper;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referrals;
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
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    [$this->a, $this->b] = [Team::forLeader(User::factory()->create()), Team::forLeader(User::factory()->create())];
    $sv->approve($sv->apply($this->g, $this->a, $cities[2], 'L', null), null);
    $sv->approve($sv->apply($this->g, $this->b, $cities[1], 'L', null), null);
    $owner = User::factory()->create();
    $this->g->owners()->attach($owner->id);
    $this->actingAs($owner)->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 20, 'due_days' => 10]);
    $ans = collect(app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'])
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $this->ref = app(Referrals::class)->send($this->a->leader, $this->g, $cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100', 'client_email' => 'tom@example.com'], true, 'Call Tom after 5pm', true)['referral'];
    app(Referrals::class)->accept($this->ref->fresh(), $this->b->leader);
    app(Pipeline::class)->move($this->ref->fresh(), $this->b->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 30000, 'earnings' => 3000]);
    app(Closing::class)->confirm($this->ref->fresh(), $this->a->leader);
    DB::table('profile_stats')->insert(['team_id' => $this->b->id, 'day' => now()->toDateString(), 'kind' => 'visit', 'count' => 3]);
    $this->events = fn () => DB::table('analytics_events')->get();
});

it('ships every source as events with IDs and amounts only, never client personal data', function () {
    $shipped = app(Shipper::class)->run();
    $types = ($this->events)()->pluck('event_type');
    expect($types)->toContain('referral.sent')->toContain('referral.accepted')->toContain('seat.claimed')->toContain('fee.due')->toContain('profile.visit')
        ->and($types->contains(fn ($t) => str_starts_with((string) $t, 'credit.')))->toBeTrue()
        ->and($shipped['referral_events'])->toBeGreaterThan(0)
        ->and((int) DB::table('analytics_events')->where('event_type', 'fee.due')->value('amount_cents'))->toBe(60000)
        ->and((int) DB::table('analytics_events')->where('event_type', 'referral.accepted')->value('referral_id'))->toBe($this->ref->id);
    $everything = json_encode(($this->events)()->all());
    expect($everything)->not->toContain('Tom')->not->toContain('Brennan')->not->toContain('555-0100')->not->toContain('tom@example.com')->not->toContain('after 5pm');
});

it('never duplicates on re-shipping, and picks up fee updates as new events', function () {
    app(Shipper::class)->run();
    $count = DB::table('analytics_events')->count();
    app(Shipper::class)->run();
    expect(DB::table('analytics_events')->count())->toBe($count);
    $fee = DB::table('referral_fees')->first();
    $this->travel(1)->minutes();
    DB::table('referral_fees')->where('id', $fee->id)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
    app(Shipper::class)->run();
    expect(DB::table('analytics_events')->where('event_type', 'fee.paid')->count())->toBe(1)->and(DB::table('analytics_events')->count())->toBe($count + 1);
});

it('loses nothing when the store is down: positions stay put and the next run catches up', function () {
    app()->instance(AnalyticsStore::class, new class implements AnalyticsStore
    {
        public function name(): string
        {
            return 'down';
        }

        public function upsert(array $events): void
        {
            throw new RuntimeException('connection refused');
        }

        public function daily(string $scope, int $id, string $from, string $to, array $types = []): array
        {
            return [];
        }

        public function health(): array
        {
            return ['ok' => false, 'detail' => 'down'];
        }

        public function reset(): void {}
    });
    expect(fn () => app(Shipper::class)->run())->toThrow(RuntimeException::class);
    expect(DB::table('analytics_cursors')->count())->toBe(0);
    app()->instance(AnalyticsStore::class, new MySqlStore);
    app(Shipper::class)->run();
    expect(DB::table('analytics_events')->where('event_type', 'referral.sent')->count())->toBe(1);
});

it('backfills from zero, keeps daily rollups in step, and replaces daily counters', function () {
    app(Shipper::class)->run();
    $before = DB::table('analytics_events')->count();
    expect(app(Shipper::class)->backfill())->toBeGreaterThan(0)->and(DB::table('analytics_events')->count())->toBe($before);
    $rows = app(AnalyticsStore::class)->daily('team', $this->b->id, now()->subDay()->toDateString(), now()->toDateString());
    $fromEvents = DB::table('analytics_events')->where('team_id', $this->b->id)->count();
    expect(array_sum(array_column($rows, 'events')))->toBe($fromEvents);
    DB::table('profile_stats')->where('team_id', $this->b->id)->update(['count' => 7]);
    app(Shipper::class)->run();
    expect((int) DB::table('analytics_events')->where('event_type', 'profile.visit')->value('value'))->toBe(7)
        ->and(DB::table('analytics_events')->where('event_type', 'profile.visit')->count())->toBe(1);
});

it('reports status in Admin → Settings → Analytics and ships every minute', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin)->get('/admin/system?tab=analytics')->assertInertia(fn ($p) => $p->where('analytics.health.ok', true)->where('analytics.sources.referral_events.pending', fn ($n) => $n > 0));
    Artisan::call('t2t:analytics', ['action' => 'ship']);
    expect(Artisan::output())->toContain('referral_events');
    Artisan::call('schedule:list');
    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan t2t:analytics ship/');
});
