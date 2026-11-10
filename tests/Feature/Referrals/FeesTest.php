<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->owner = User::factory()->create();
});

/** Two teams in a network and one accepted referral; returns [referral, sender team, receiver team]. */
function deal($test, string $slug): array
{
    $g = Group::where('slug', $slug)->firstOrFail();
    $g->owners()->syncWithoutDetaching([$test->owner->id]);
    $ag = app(Agreements::class);
    $ag->save($g->industry, $ag->current($g->industry)['clauses'], null);
    $ag->approve($g->industry, null, 'J. Counsel', 'ok');
    $s = app(Seats::class);
    $a = tap(Team::forLeader(User::factory()->create(['first_name' => 'Jordan', 'last_name' => 'Hale'])))->update(['name' => 'Sender Co']);
    $b = tap(Team::forLeader(User::factory()->create(['first_name' => 'Chris', 'last_name' => 'Delgado'])))->update(['name' => 'Receiver Co']);
    $s->approve($s->apply($g, $a, $test->cities[0], 'L', null), null);
    $s->approve($s->apply($g, $b, $test->cities[1], 'L', null), null);
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $r = app(Referrals::class)->send($a->leader, $g->fresh(), $test->cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null)['referral'];

    return [$r, $a, $b, $g];
}

function closeWon(Referral $r, Team $b, Team $a): void
{
    app(Pipeline::class)->move($r->fresh(), $b->leader, 'won', null, ['closing_date' => '2026-12-20', 'closing_value' => 31500]);
    app(Closing::class)->confirm($r->fresh(), $a->leader);
}

it('lets owners set a default fee in open industries only, stated in the agreement', function () {
    [$r, $a, $b, $g] = deal($this, 'solar-installers-network');
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 95, 'due_days' => 10])->assertSessionHasErrors('value');
    $this->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 25, 'due_days' => 10])->assertSessionHas('status', 'fee-default-saved');
    app(Referrals::class)->accept($r->fresh(), $b->leader);
    expect((string) DB::table('referral_agreements')->value('sections'))->toContain('25% of the closing value');
    Group::where('slug', 'mortgage-lenders-circle')->firstOrFail()->owners()->attach($this->owner->id);
    $this->post('/app/group/mortgage-lenders-circle/fee', ['type' => 'percent', 'value' => 10, 'due_days' => 10])->assertSessionHasErrors('type');
});

it('agrees a different fee for one referral before signing, and regenerates the agreement', function () {
    [$r, $a, $b, $g] = deal($this, 'solar-installers-network');
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 25, 'due_days' => 10]);
    app(Referrals::class)->accept($r->fresh(), $b->leader);
    $url = "/app/referrals/{$r->ulid}";
    $this->actingAs($b->leader)->post("{$url}/fee", ['type' => 'flat', 'value' => 750])->assertSessionHas('status', 'fee-proposed');
    $this->post("{$url}/fee/accept")->assertStatus(422); // can't accept your own proposal
    $this->actingAs($a->leader)->post("{$url}/fee/accept")->assertSessionHas('status', 'fee-agreed');
    expect($r->fresh()->fee_type)->toBe('flat')->and((string) DB::table('referral_agreements')->value('sections'))->toContain('$750.00 per closed referral');

    $this->post("{$url}/sign", ['name' => 'Jordan Hale', 'consent' => true]);
    $this->post("{$url}/fee", ['type' => 'percent', 'value' => 30])->assertSessionHasErrors('value');
});

it('calculates the fee on a confirmed close; the receiver pays, the sender confirms', function () {
    [$r, $a, $b] = deal($this, 'solar-installers-network');
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 25, 'due_days' => 10]);
    app(Referrals::class)->accept($r->fresh(), $b->leader);
    closeWon($r, $b, $a);
    $fee = DB::table('referral_fees')->first();
    expect((int) $fee->amount_cents)->toBe(787500)->and((int) $fee->payer_team_id)->toBe($b->id)->and($fee->due_on)->toBe(now()->addDays(10)->toDateString());
    Storage::disk('private')->assertExists($fee->statement_path);
    Notification::assertSentTo($b->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'Referral fee due: $7,875.00'));

    $this->actingAs($a->leader)->post("/app/earnings/{$fee->id}/paid")->assertForbidden();
    $this->actingAs($b->leader)->post("/app/earnings/{$fee->id}/paid", ['reference' => 'ACH 4417'])->assertSessionHas('status', 'fee-paid');
    $this->actingAs($a->leader)->post("/app/earnings/{$fee->id}/received")->assertSessionHas('status', 'fee-received');
    $this->get('/app/earnings')->assertInertia(fn ($p) => $p->component('app/Earnings')->where('fees.0.incoming', true)->where('totals.receivedThisYear', 7875));
    $this->get("/app/earnings/{$fee->id}/statement.pdf")->assertOk();
    $this->actingAs(User::factory()->create())->get("/app/earnings/{$fee->id}/statement.pdf")->assertNotFound();
    $csv = $this->actingAs($a->leader)->get('/app/earnings/export.csv?month='.now()->format('Y-m'))->assertOk()->getContent();
    expect($csv)->toContain('Receivable')->toContain('7875.00')->toContain('ACH 4417');
});

it('reminds the payer on the due date and a week later, once each', function () {
    [$r, $a, $b] = deal($this, 'solar-installers-network');
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/fee', ['type' => 'flat', 'value' => 500, 'due_days' => 5]);
    app(Referrals::class)->accept($r->fresh(), $b->leader);
    closeWon($r, $b, $a);
    $count = fn (string $w) => Notification::sent($b->leader, InAppNotification::class)->filter(fn ($n) => str_starts_with($n->title, $w))->count();
    $this->travel(5)->days();
    Artisan::call('t2t:performance');
    Artisan::call('t2t:performance');
    $this->travel(7)->days();
    Artisan::call('t2t:performance');
    expect($count('Due today'))->toBe(1)->and($count('Overdue'))->toBe(1);
});

it('never creates fees in restricted industries', function () {
    [$r, $a, $b] = deal($this, 'mortgage-lenders-circle');
    app(Referrals::class)->accept($r->fresh(), $b->leader);
    closeWon($r, $b, $a);
    expect(DB::table('referral_fees')->count())->toBe(0);
    $this->actingAs($a->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('fee', null));
});
