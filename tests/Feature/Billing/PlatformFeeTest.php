<?php

use App\Domains\Billing\Invoicing;
use App\Domains\Billing\TeamLock;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Compliance;
use App\Domains\Network\Industries\Models\PlatformFeeRule;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Fees;
use App\Domains\Referrals\Referral;
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
    $s->set('payments.stripe.publishable_key', 'pk_test_1');
    $s->set('payments.stripe.secret_key', 'sk_test_1', secret: true);
    $s->set('billing.platform_fee_collection', '1');
    $this->decline = false;
    $this->amounts = [];
    Http::fake(['api.stripe.com/*' => function ($request) {
        $this->amounts[] = (int) $request['amount'];

        return $this->decline ? Http::response(['error' => ['message' => 'Your card was declined.', 'code' => 'card_declined']], 402)
            : Http::response(['id' => 'pi_'.count($this->amounts), 'status' => 'succeeded']);
    }]);
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('super_admin');
    $this->g = Group::with('industry')->where('slug', 'solar-installers-network')->firstOrFail();
    $this->g->industry->forceFill(['compliance_approved_at' => now(), 'compliance_approved_by' => $this->admin->id])->save();
    $this->referrer = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Referrer']);
    $this->payer = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Payer']);
    DB::table('billing_customers')->insert(['owner_type' => 'team', 'owner_id' => $this->referrer->id, 'stripe_customer_id' => 'cus_1', 'payment_method_id' => 'pm_1', 'created_at' => now(), 'updated_at' => now()]);
    // A Won referral with a $1,500 referral fee owed to the referring team.
    $this->fee = function (int $platformCents = 25000): int {
        $ref = DB::table('referrals')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'group_id' => $this->g->id, 'seat_id' => DB::table('seats')->insertGetId(['group_id' => $this->g->id, 'territory_type' => 'city', 'territory_id' => 1, 'territory_name' => 'X', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]),
            'sender_team_id' => $this->referrer->id, 'sender_user_id' => $this->referrer->leader_id, 'receiver_team_id' => $this->payer->id, 'routed_to' => 'holder', 'status' => 'accepted', 'client_name' => 'Tom Brennan',
            'answers' => '{}', 'consent_at' => now(), 'consent_method' => 'sender_attested', 'consent_text' => 'x', 'respond_by' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('referral_fees')->insertGetId(['referral_id' => $ref, 'payer_team_id' => $this->payer->id, 'payee_team_id' => $this->referrer->id, 'type' => 'flat', 'rate' => 150000, 'basis_cents' => null,
            'amount_cents' => 150000, 'platform_fee_cents' => $platformCents, 'platform_fee_status' => 'pending', 'due_on' => now()->addDays(10)->toDateString(), 'status' => 'due', 'created_at' => now(), 'updated_at' => now()]);
    };
});

it('gives every fee-allowed industry a $250 flat default that switches on when fees are unlocked', function () {
    $rule = PlatformFeeRule::query()->where('industry_id', $this->g->industry_id)->whereNull('group_id')->firstOrFail();
    expect($rule->mode)->toBe('flat')->and($rule->flat_cents)->toBe(25000)->and($rule->enabled)->toBeFalse();
    expect(PlatformFeeRule::query()->whereIn('industry_id', DB::table('industries')->where('tier', '!=', 'A')->pluck('id'))->exists())->toBeFalse();
    app(Compliance::class)->unlockFees($this->admin, 'Licensing and fund routing approved by counsel');
    expect($rule->fresh()->enabled)->toBeTrue();
    $ref = Referral::query()->with('group.industry')->findOrFail(DB::table('referral_fees')->where('id', ($this->fee)())->value('referral_id'));
    expect(Fees::platformFee($ref, 150000))->toBe(25000);
});

it('lets only a super admin give a group its own fee: a percentage, a flat amount, none, or back to the default', function () {
    app(Compliance::class)->unlockFees($this->admin, 'Licensing and fund routing approved by counsel');
    $ref = Referral::query()->with('group.industry')->findOrFail(DB::table('referral_fees')->where('id', ($this->fee)())->value('referral_id'));
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(App\Domains\Identity\Authorization\Permissions::STAFF);
    $staff->givePermissionTo('groups.manage');
    $this->actingAs($staff)->put('/admin/groups/solar-installers-network/platform-fee', ['mode' => 'percent', 'percent' => 10])->assertForbidden();
    $this->actingAs($staff)->get('/admin/groups/solar-installers-network')->assertInertia(fn ($p) => $p->where('platformFee.canEdit', false)->where('platformFee.industry.flatCents', 25000));

    $this->actingAs($this->admin)->put('/admin/groups/solar-installers-network/platform-fee', ['mode' => 'percent', 'percent' => 10])->assertSessionHas('status', 'group-saved');
    expect(Fees::platformFee($ref, 150000))->toBe(15000);
    $this->put('/admin/groups/solar-installers-network/platform-fee', ['mode' => 'flat', 'flat' => 99])->assertSessionHasNoErrors();
    expect(Fees::platformFee($ref, 150000))->toBe(9900);
    $this->put('/admin/groups/solar-installers-network/platform-fee', ['mode' => 'none'])->assertSessionHasNoErrors();
    expect(Fees::platformFee($ref, 150000))->toBe(0);
    $this->put('/admin/groups/solar-installers-network/platform-fee', ['mode' => 'default'])->assertSessionHasNoErrors();
    expect(Fees::platformFee($ref, 150000))->toBe(25000);
});

it('charges the platform fee to the referring team’s card as soon as they confirm they were paid', function () {
    $fee = ($this->fee)();
    app(Fees::class)->markPaid($fee, $this->payer->leader, 'CHK 1001');
    expect($this->amounts)->toBe([]);
    app(Fees::class)->markReceived($fee, $this->referrer->leader);
    $inv = DB::table('invoices')->latest('id')->first();
    expect($this->amounts)->toBe([25000])->and($inv->source_key)->toBe("fee:{$fee}")->and($inv->status)->toBe('paid')
        ->and(DB::table('referral_fees')->where('id', $fee)->value('platform_fee_status'))->toBe('paid');
    // The monthly run doesn't charge it again, and can still bill the same team that month.
    app(Invoicing::class)->run(now()->subMonth()->format('Y-m'));
    expect($this->amounts)->toBe([25000]);
});

it('counts a fee as received 14 days after it was marked paid, unless disputed, and charges the platform fee then', function () {
    $fee = ($this->fee)();
    app(Fees::class)->markPaid($fee, $this->payer->leader, null);
    $this->travel(13)->days();
    app(Fees::class)->remind();
    expect(DB::table('referral_fees')->where('id', $fee)->value('status'))->toBe('paid');
    $this->travel(2)->days();
    app(Fees::class)->remind();
    expect(DB::table('referral_fees')->where('id', $fee)->value('status'))->toBe('received')->and($this->amounts)->toBe([25000]);
});

it('locks the referring team when the platform fee stays unpaid past the grace days, until it’s paid', function () {
    $this->decline = true;
    $fee = ($this->fee)();
    app(Fees::class)->markPaid($fee, $this->payer->leader, null);
    app(Fees::class)->markReceived($fee, $this->referrer->leader);
    $inv = DB::table('invoices')->latest('id')->first();
    expect($inv->status)->toBe('failed')->and(TeamLock::locked($this->referrer))->toBeFalse();
    $this->travel(8)->days();
    expect(TeamLock::locked($this->referrer))->toBeTrue()->and(TeamLock::lockedFor($this->referrer->leader))->toBeTrue();
    $this->actingAs($this->referrer->leader)->get('/app')->assertRedirect('/app/renew');
    $this->get('/app/renew')->assertInertia(fn ($p) => $p->where('unpaidCharges', 1)->where('invoices.0.id', (int) $inv->id));

    $this->decline = false;
    $this->post("/app/billing/team/invoices/{$inv->id}/pay")->assertRedirect();
    expect(TeamLock::locked($this->referrer))->toBeFalse();
    $this->get('/app')->assertOk();
});
