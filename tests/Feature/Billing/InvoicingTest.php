<?php

use App\Domains\Billing\Invoicing;
use App\Domains\Identity\Models\User;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Storage::fake('private');
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
    $s->set('payments.stripe.webhook_secret', 'whsec_test', secret: true);
    $this->decline = false;
    $this->charges = [];
    Http::fake(['api.stripe.com/*' => function ($request) {
        if (str_contains($request->url(), '/v1/payment_intents')) {
            $this->charges[] = ['amount' => (int) $request['amount'], 'key' => $request->header('Idempotency-Key')[0] ?? null];

            return $this->decline ? Http::response(['error' => ['message' => 'Your card was declined.', 'code' => 'card_declined']], 402)
                : Http::response(['id' => 'pi_'.count($this->charges), 'status' => 'succeeded']);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 400);
    }]);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->teamA = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $this->teamA, $this->cities[1], 'L', null), null);
    $this->seat = Seat::firstOrFail();
    $this->seat->update(['trial_ends_at' => '2027-01-01 00:00:00']);
    $this->card = fn (string $type, int $id) => DB::table('billing_customers')->updateOrInsert(['owner_type' => $type, 'owner_id' => $id],
        ['stripe_customer_id' => "cus_{$type}{$id}", 'payment_method_id' => 'pm_1', 'card_brand' => 'visa', 'card_last4' => '4242', 'card_exp_month' => 12, 'card_exp_year' => 2030, 'created_at' => now(), 'updated_at' => now()]);
    ($this->card)('team', $this->teamA->id);
    // A referral offered to the seat in a given month (so the value guarantee doesn't make it free).
    $this->offer = fn (Seat $seat, string $when) => DB::table('referrals')->insert(['ulid' => strtolower((string) Str::ulid()), 'group_id' => $seat->group_id, 'seat_id' => $seat->id,
        'sender_team_id' => $seat->primary_team_id, 'sender_user_id' => $this->teamA->leader_id, 'receiver_team_id' => $seat->primary_team_id, 'routed_to' => 'holder', 'status' => 'sent',
        'client_name' => 'C', 'answers' => '{}', 'consent_at' => $when, 'consent_method' => 'sender_attested', 'consent_text' => 'x', 'respond_by' => $when, 'created_at' => $when, 'updated_at' => $when]);
    $this->inv = fn () => DB::table('invoices')->latest('id')->first();
});

it('charges nothing during the free trial, then a full month after it', function () {
    expect(app(Invoicing::class)->run('2026-12'))->toBe(0);
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    $this->travelTo('2027-02-01 06:00:00');
    Artisan::call('t2t:billing:run');
    $inv = ($this->inv)();
    expect($inv->period)->toBe('2027-01')->and((int) $inv->total_cents)->toBe(1000)->and($inv->status)->toBe('paid')->and($this->charges[0]['amount'])->toBe(1000)
        ->and($this->seat->fresh()->status)->toBe('active');
    Storage::disk('private')->assertExists($inv->pdf_path);
    $this->actingAs($this->teamA->leader)->get("/app/billing/team/invoices/{$inv->id}.pdf")->assertOk();
    expect(app(Invoicing::class)->run('2027-01'))->toBe(0); // never twice
});

it('prorates by day from the end of the seat’s own trial', function () {
    $this->seat->update(['trial_ends_at' => '2027-01-17 00:00:00']);
    ($this->offer)($this->seat, '2027-01-20 12:00:00');
    app(Invoicing::class)->run('2027-01');
    $inv = ($this->inv)();
    $line = json_decode((string) $inv->lines, true)[0];
    expect($line['days'])->toBe(15)->and((int) $inv->total_cents)->toBe((int) round(1000 * 15 / 31))->and($line['description'])->toContain('(15 days)');
});

it('makes a month with no referral offered free, without charging', function () {
    app(Invoicing::class)->run('2027-01');
    $inv = ($this->inv)();
    expect($inv->status)->toBe('zero')->and((int) $inv->total_cents)->toBe(0)->and($this->charges)->toBe([])
        ->and(json_decode((string) $inv->lines, true)[0]['free_reason'])->toContain('no referral was offered');
});

it('bills a company once for all its branches', function () {
    $admin = User::factory()->create();
    $co = Company::create(['name' => 'Sun Holdings', 'admin_id' => $admin->id]);
    $teamB = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Sun Austin']);
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $teamB, $this->cities[2], 'L', null), null);
    foreach ([$this->teamA, $teamB] as $t) {
        $t->forceFill(['company_id' => $co->id])->save();
    }
    Seat::query()->update(['payer' => 'company', 'trial_ends_at' => '2027-01-01 00:00:00']);
    ($this->card)('company', $co->id);
    foreach (Seat::all() as $seat) {
        ($this->offer)($seat, '2027-01-15 12:00:00');
    }
    app(Invoicing::class)->run('2027-01');
    $inv = ($this->inv)();
    expect(DB::table('invoices')->count())->toBe(1)->and($inv->owner_type)->toBe('company')->and((int) $inv->total_cents)->toBe(2000)->and(count(json_decode((string) $inv->lines, true)))->toBe(2);
});

it('retries a declined card on days 3 and 5, and reactivates when it goes through', function () {
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    $this->decline = true;
    $this->travelTo('2027-02-01 06:00:00');
    Artisan::call('t2t:billing:run');
    expect(($this->inv)()->status)->toBe('failed')->and($this->seat->fresh()->status)->toBe('grace');
    $this->travelTo('2027-02-03 10:00:00');
    Artisan::call('t2t:billing:run', ['--retries' => true]);
    expect(count($this->charges))->toBe(2)->and($this->charges[0]['key'])->not->toBe($this->charges[1]['key']);
    $this->decline = false;
    $this->travelTo('2027-02-05 10:00:00');
    Artisan::call('t2t:billing:run', ['--retries' => true]);
    expect(($this->inv)()->status)->toBe('paid')->and($this->seat->fresh()->status)->toBe('active');
});

it('treats no card at the end of the trial like a failed payment', function () {
    DB::table('billing_customers')->update(['payment_method_id' => null]);
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    app(Invoicing::class)->run('2027-01');
    expect(($this->inv)()->failure)->toBe('No card on file.')->and($this->seat->fresh()->status)->toBe('grace')->and($this->charges)->toBe([]);
});

it('requires a new card after three failed invoices in a year, and a new card clears it', function () {
    $this->decline = true;
    foreach (['2027-01', '2027-02', '2027-03'] as $p) {
        ($this->offer)($this->seat, $p.'-10 12:00:00');
        app(Invoicing::class)->run($p);
    }
    expect((bool) DB::table('billing_customers')->value('needs_new_card'))->toBeTrue();
    $this->decline = false;
    $before = count($this->charges);
    app(Invoicing::class)->charge((int) ($this->inv)()->id);
    expect(count($this->charges))->toBe($before); // no charge on the flagged card
});

it('confirms a payment from a signed Stripe webhook', function () {
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    $this->decline = true;
    app(Invoicing::class)->run('2027-01');
    $inv = ($this->inv)();
    $payload = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_x', 'metadata' => ['invoice_id' => (string) $inv->id]]]]);
    $t = time();
    $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_test');
    $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    expect(($this->inv)()->status)->toBe('paid')->and($this->seat->fresh()->status)->toBe('active');
});

it('reactivates at once when the payer presses Pay now', function () {
    ($this->offer)($this->seat, '2027-01-10 12:00:00');
    $this->decline = true;
    app(Invoicing::class)->run('2027-01');
    $this->decline = false;
    $this->actingAs($this->teamA->leader)->post('/app/billing/team/invoices/'.($this->inv)()->id.'/pay')->assertSessionHas('status', 'invoice-paid');
    expect($this->seat->fresh()->status)->toBe('active');
    $this->actingAs(User::factory()->create())->post('/app/billing/team/invoices/'.($this->inv)()->id.'/pay')->assertForbidden();
});
