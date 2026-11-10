<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    $s = app(Settings::class);
    $s->set('payments.driver', 'stripe');
    $s->set('payments.stripe.publishable_key', 'pk_test_123');
    $s->set('payments.stripe.secret_key', 'sk_test_123', secret: true);
    $this->stripeCalls = [];
    Http::fake(['api.stripe.com/*' => function ($request) {
        $this->stripeCalls[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($request->url(), '/v1/customers') => Http::response(['id' => 'cus_TEAM1']),
            str_contains($request->url(), '/v1/setup_intents') => Http::response(['id' => 'seti_1', 'client_secret' => 'seti_1_secret_abc']),
            str_contains($request->url(), '/v1/payment_methods/pm_good') => Http::response(['id' => 'pm_good', 'customer' => 'cus_TEAM1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2029]]),
            str_contains($request->url(), '/v1/payment_methods/pm_other') => Http::response(['id' => 'pm_other', 'customer' => 'cus_SOMEONE_ELSE', 'card' => ['brand' => 'visa', 'last4' => '1111']]),
            str_contains($request->url(), '/v1/customers/') => Http::response(['id' => 'cus_TEAM1']),
            default => Http::response(['error' => ['message' => 'unexpected']], 400),
        };
    }]);
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $seats = app(Seats::class);
    $this->team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    $seats->approve($seats->apply($g, $this->team, $cities[1], 'L', null), null);
    $this->seat = Seat::firstOrFail();
});

it('shows each account the person pays for, with its seats', function () {
    $this->actingAs($this->team->leader)->get('/app/billing')->assertOk()->assertInertia(fn ($p) => $p->component('app/Billing')->where('stripeKey', 'pk_test_123')
        ->where('accounts.0.type', 'team')->where('accounts.0.card', null)->where('accounts.0.seats.0.price', 10)->where('accounts.0.seats.0.trialEnds', $this->seat->trial_ends_at->toDateString()));
    $co = Company::create(['name' => 'Sun Holdings', 'admin_id' => $this->team->leader_id]);
    $this->team->forceFill(['company_id' => $co->id])->save();
    $this->seat->update(['payer' => 'company']);
    $this->get('/app/billing')->assertInertia(fn ($p) => $p->has('accounts', 2)->has('accounts.0.seats', 0)->where('accounts.1.type', 'company')->where('accounts.1.seats.0.team', 'Hurricane Sun'));
});

it('starts a secure card setup, creating the Stripe customer once', function () {
    $this->actingAs($this->team->leader)->postJson('/app/billing/team/setup-intent')->assertOk()->assertJson(['clientSecret' => 'seti_1_secret_abc']);
    $this->postJson('/app/billing/team/setup-intent')->assertOk();
    expect(collect($this->stripeCalls)->filter(fn ($c) => $c === 'POST /v1/customers')->count())->toBe(1)
        ->and(DB::table('billing_customers')->value('stripe_customer_id'))->toBe('cus_TEAM1');
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->team->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->postJson('/app/billing/team/setup-intent')->assertForbidden();
    app(Settings::class)->set('payments.driver', 'none');
    $this->actingAs($this->team->leader)->postJson('/app/billing/team/setup-intent')->assertStatus(503);
});

it('saves only a card that belongs to this account, keeping just a reference and its last digits', function () {
    $this->actingAs($this->team->leader)->postJson('/app/billing/team/setup-intent');
    $this->post('/app/billing/team/card', ['payment_method' => 'pm_other'])->assertSessionHasErrors('card');
    $this->post('/app/billing/team/card', ['payment_method' => 'pm_good'])->assertSessionHas('status', 'card-saved');
    $row = DB::table('billing_customers')->first();
    expect($row->payment_method_id)->toBe('pm_good')->and($row->card_last4)->toBe('4242')->and($row->card_brand)->toBe('visa')->and((int) $row->card_exp_year)->toBe(2029);
    $this->get('/app/billing')->assertInertia(fn ($p) => $p->where('accounts.0.card.last4', '4242'));
});

it('sends trial-ending notices at 14, 7 and 1 days, once each, saying whether a card is on file', function () {
    $count = fn () => Notification::sent($this->team->leader, InAppNotification::class)->filter(fn ($n) => str_contains($n->title, 'Free trial for'))->count();
    $this->travelTo($this->seat->trial_ends_at->copy()->subDays(14)->addHour());
    Artisan::call('t2t:billing:trial-notices');
    Artisan::call('t2t:billing:trial-notices');
    expect($count())->toBe(1);
    Notification::assertSentTo($this->team->leader, InAppNotification::class, fn ($n) => str_contains($n->body, 'Add a card before then'));
    $this->travelTo($this->seat->trial_ends_at->copy()->subDays(7)->addHour());
    Artisan::call('t2t:billing:trial-notices');
    $this->travelTo($this->seat->trial_ends_at->copy()->subHours(20));
    Artisan::call('t2t:billing:trial-notices');
    expect($count())->toBe(3);
});

it('tells the company admin and the city leader for company-paid seats', function () {
    $admin = User::factory()->create();
    $co = Company::create(['name' => 'Sun Holdings', 'admin_id' => $admin->id]);
    $this->team->forceFill(['company_id' => $co->id])->save();
    $this->seat->update(['payer' => 'company']);
    $this->travelTo($this->seat->trial_ends_at->copy()->subDays(14)->addHour());
    Artisan::call('t2t:billing:trial-notices');
    Notification::assertSentTo($admin, InAppNotification::class, fn ($n) => str_contains($n->title, 'Free trial for'));
    Notification::assertSentTo($this->team->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'Free trial for'));
});

it('allows Stripe in the security policy on billing pages only', function () {
    $billing = $this->actingAs($this->team->leader)->get('/app/billing')->headers->get('Content-Security-Policy');
    $other = $this->get('/app')->headers->get('Content-Security-Policy');
    expect($billing)->toContain('https://js.stripe.com')->toContain('frame-src https://js.stripe.com')
        ->and($other)->not->toContain('stripe');
});
