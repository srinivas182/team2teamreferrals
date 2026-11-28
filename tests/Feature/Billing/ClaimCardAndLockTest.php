<?php

use App\Domains\Billing\TeamLock;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get(); // 1 Hurricane, 2 Austin, 3 Houston
    $this->stripeOn = function (): void {
        $s = app(Settings::class);
        $s->set('payments.driver', 'stripe');
        $s->set('payments.stripe.publishable_key', 'pk_test_123');
        $s->set('payments.stripe.secret_key', 'sk_test_abc', secret: true);
    };
    $this->card = fn (Team $t) => DB::table('billing_customers')->insert(['owner_type' => 'team', 'owner_id' => $t->id, 'stripe_customer_id' => 'cus_1', 'payment_method_id' => 'pm_1',
        'card_brand' => 'visa', 'card_last4' => '4242', 'needs_new_card' => false, 'created_at' => now(), 'updated_at' => now()]);
});

it('takes a card when claiming a city, reuses it for the next one, and says when billing starts', function () {
    ($this->stripeOn)();
    $team = Team::forLeader(User::factory()->create());
    $this->actingAs($team->leader)->get('/app/groups/solar-installers-network/apply')->assertInertia(fn ($p) => $p->where('billing.stripeKey', 'pk_test_123')->where('billing.card', null)
        ->where('billing.reward', true)->where('billing.firstCharge', now()->addMonths(1)->addMonthNoOverflow()->startOfMonth()->toFormattedDateString()));
    $this->post('/app/groups/solar-installers-network/apply', ['city_id' => $this->cities[2]->id, 'license_number' => 'L'])->assertSessionHasErrors('card');
    ($this->card)($team);
    $this->post('/app/groups/solar-installers-network/apply', ['city_id' => $this->cities[2]->id, 'license_number' => 'L'])->assertSessionHasNoErrors();
    $this->get('/app/groups/solar-installers-network/apply')->assertInertia(fn ($p) => $p->where('billing.card', ['brand' => 'visa', 'last4' => '4242', 'payer' => 'team']));
    // Without payments switched on, no card is asked for.
    app(Settings::class)->set('payments.driver', null);
    $other = Team::forLeader(User::factory()->create());
    $this->actingAs($other->leader)->post('/app/groups/solar-installers-network/apply', ['city_id' => $this->cities[3]->id, 'license_number' => 'L'])->assertSessionHasNoErrors();
});

it('starts Tier A groups at $25 with the sharing reward on, and Tier B and C groups with it off', function () {
    expect(Group::defaultPricing($this->g->industry))->toMatchArray(['seat_price' => 25, 'founding_price' => 25, 'trial_months' => 1, 'sharing_reward' => true]);
    $mortgage = App\Domains\Network\Industries\Models\Industry::where('slug', 'mortgage')->firstOrFail();
    $hospice = App\Domains\Network\Industries\Models\Industry::where('slug', 'hospice')->firstOrFail();
    expect(Group::defaultPricing($mortgage)['sharing_reward'])->toBeFalse()->and(Group::defaultPricing($hospice)['sharing_reward'])->toBeFalse();
    // The claim page shows the reward only while it's on.
    $this->g->update(['pricing' => [...$this->g->pricing, 'sharing_reward' => false]]);
    $this->actingAs(User::factory()->create())->get('/app/groups/solar-installers-network/apply')->assertInertia(fn ($p) => $p->where('billing.reward', false));
});

it('locks a team whose every seat is unpaid, except Renew, Billing and clients it already accepted, and lists what it missed', function () {
    $sv = app(Seats::class);
    $holder = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Houston Holder']);
    $backup = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Houston Backup']);
    $sender = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Austin Sender']);
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $holder->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $sv->approve($sv->apply($this->g, $holder, $this->cities[3], 'L', null), null);
    $sv->approve($sv->apply($this->g, $backup, $this->cities[3], 'L', null), null);
    $sv->approve($sv->apply($this->g, $sender, $this->cities[2], 'L', null), null);
    $ans = collect(app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'])->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
        'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
    }])->all();
    $send = fn (string $phone) => app(Referrals::class)->send($sender->leader, $this->g, $this->cities[3], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => $phone], true, null, true)['referral'];
    $kept = $send('+14355550401');
    app(Referrals::class)->accept($kept->fresh(), $holder->leader);

    // Payment fails, grace runs out: the seat goes inactive and the team (its only seat) is locked.
    $seat = Seat::query()->where(['group_id' => $this->g->id, 'primary_team_id' => $holder->id])->firstOrFail();
    $sv->paymentFailed($seat);
    $this->travel(8)->days();
    $sv->tick();
    expect($seat->fresh()->status)->toBe('inactive')->and(TeamLock::locked($holder))->toBeTrue()->and(TeamLock::locked($sender))->toBeFalse();

    // New referrals go to the backup and are listed as missed.
    $missed = $send('+14355550402');
    expect($missed->receiver_team_id)->toBe($backup->id);

    foreach ([$holder->leader, $member] as $u) {
        $this->actingAs($u)->get('/app')->assertRedirect('/app/renew');
        $this->get('/app/send')->assertRedirect('/app/renew');
        if ($u->is($holder->leader)) {
            $this->get("/app/referrals/{$kept->ulid}")->assertOk(); // already accepted: still workable
        }
        $this->get('/app/billing')->assertOk();
    }
    $this->actingAs($holder->leader)->get('/app/renew')->assertInertia(fn ($p) => $p->component('app/Renew')->where('locked', true)->where('canPay', true)
        ->has('seats', 1)->where('missed.0.client', 'Tom B.')->has('missed', 1));
    $this->actingAs($member)->get('/app/renew')->assertInertia(fn ($p) => $p->where('canPay', false)->where('leader', $holder->leader->name)->where('invoices', []));

    // Paying brings it back at once.
    $sv->paymentSucceeded($seat->fresh());
    expect(TeamLock::locked($holder))->toBeFalse();
    $this->actingAs($holder->leader)->get('/app')->assertOk();
});

it('doesn’t lock a team that still has a paid seat', function () {
    $sv = app(Seats::class);
    $team = Team::forLeader(User::factory()->create());
    $sv->approve($sv->apply($this->g, $team, $this->cities[2], 'L', null), null);
    $sv->approve($sv->apply($this->g, $team, $this->cities[3], 'L', null), null);
    Seat::query()->where('primary_team_id', $team->id)->orderBy('id')->first()->forceFill(['status' => 'inactive', 'inactive_at' => now()])->save();
    expect(TeamLock::locked($team))->toBeFalse();
    $this->actingAs($team->leader)->get('/app')->assertOk();
});

it('asks for a card when accepting an invitation or a waitlist offer, and gives every group a 1-month trial', function () {
    ($this->stripeOn)();
    expect(Group::defaultPricing($this->g->industry)['trial_months'])->toBe(1);
    $owner = User::factory()->create();
    DB::table('group_owners')->insert(['group_id' => $this->g->id, 'user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $invited = User::factory()->create(['email' => 'invited@example.com']);
    $seat = Seat::query()->create(['group_id' => $this->g->id, 'territory_type' => 'city', 'territory_id' => $this->cities[2]->id, 'territory_name' => 'Austin, TX']);
    $token = Illuminate\Support\Str::random(48);
    DB::table('seat_invites')->insert(['group_id' => $this->g->id, 'seat_id' => $seat->id, 'email' => 'invited@example.com', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(14), 'invited_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($invited)->get("/invites/{$token}")->assertInertia(fn ($p) => $p->where('billing.card', null)->where('billing.trialMonths', 1));
    $this->post("/invites/{$token}/accept")->assertSessionHasErrors('card');
    ($this->card)(Team::of($invited) ?? Team::forLeader($invited));
    $this->post("/invites/{$token}/accept")->assertSessionHasNoErrors();
});
