<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\ProfileStats;
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->b] = [$mk('Sender Co'), $mk('Receiver Co')];
    $s->approve($s->apply($this->g, $this->a, $cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $cities[1], 'L', null), null);
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 30, 'due_days' => 10]);
    (new App\Domains\Network\Industries\Models\PlatformFeeRule)->forceFill(['industry_id' => $this->g->industry_id, 'group_id' => null, 'mode' => 'percent', 'percent' => 10, 'charge_at' => 'closing', 'enabled' => true, 'enabled_at' => now()])->save();
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->n = 0;
    $this->newDeal = function () use ($cities, $ans) {
        $this->n++;
        $r = app(Referrals::class)->send($this->a->leader, $this->g->fresh(), $cities[1], [...$ans, 'client_name' => "Client {$this->n}", 'client_phone' => '+143555503'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
        app(Referrals::class)->accept($r->fresh(), $this->b->leader);
        app(Pipeline::class)->move($r->fresh(), $this->b->leader, 'proposal', null);
        app(Pipeline::class)->move($r->fresh(), $this->b->leader, 'won', null, ['closing_date' => '2026-12-20', 'closing_value' => 100000, 'earnings' => 5000]);

        return $r->fresh();
    };
});

it('lets the paying team dispute a fee, and the owner confirm, adjust or waive it', function () {
    $r = ($this->newDeal)();
    app(Closing::class)->confirm($r, $this->a->leader);
    $fee = DB::table('referral_fees')->first();
    $this->actingAs($this->a->leader)->post("/app/earnings/{$fee->id}/dispute", ['reason' => 'Not my fee to dispute'])->assertForbidden();
    $this->actingAs($this->b->leader)->post("/app/earnings/{$fee->id}/dispute", ['reason' => 'Commission was split with a co-agent'])->assertSessionHas('status', 'fee-disputed');
    Notification::assertSentTo($this->owner, InAppNotification::class, fn ($n) => str_contains($n->title, 'Referral fee disputed'));
    $this->actingAs($this->owner)->get('/app/group/solar-installers-network/disputes')->assertInertia(fn ($p) => $p->component('app/group/Disputes')->has('fees', 1));

    $this->actingAs($this->b->leader)->post("/app/disputes/fee/{$fee->id}", ['decision' => 'waived', 'note' => 'x'])->assertForbidden();
    $this->actingAs($this->owner)->post("/app/disputes/fee/{$fee->id}", ['decision' => 'adjusted', 'note' => 'Half the commission went to the co-agent', 'amount' => 2000])->assertSessionHasErrors('amount');
    $this->post("/app/disputes/fee/{$fee->id}", ['decision' => 'adjusted', 'note' => 'Half the commission went to the co-agent', 'amount' => 750])->assertSessionHas('status', 'dispute-decided');
    $row = DB::table('referral_fees')->first();
    expect((int) $row->amount_cents)->toBe(75000)->and((int) $row->platform_fee_cents)->toBe(7500)->and($row->status)->toBe('due')->and($row->resolution)->toBe('adjusted');
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'adjusted to $750.00'));

    $r2 = ($this->newDeal)();
    app(Closing::class)->confirm($r2, $this->a->leader);
    $fee2 = DB::table('referral_fees')->where('referral_id', $r2->id)->first();
    $this->actingAs($this->b->leader)->post("/app/earnings/{$fee2->id}/dispute", ['reason' => 'Client cancelled after closing']);
    $this->actingAs($this->owner)->post("/app/disputes/fee/{$fee2->id}", ['decision' => 'waived', 'note' => 'Sale fell through']);
    expect(DB::table('referral_fees')->where('id', $fee2->id)->first())->status->toBe('waived');
});

it('lets the owner confirm a disputed close (the fee follows) or reopen it where it was', function () {
    $r = ($this->newDeal)();
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/close/dispute", ['reason' => 'Client says it hasn’t closed']);
    $this->actingAs($this->owner)->post("/app/disputes/close/{$r->ulid}", ['confirm' => false, 'note' => 'Escrow not closed yet'])->assertSessionHas('status', 'dispute-decided');
    $r->refresh();
    expect($r->stage)->toBe('proposal')->and($r->closed_at)->toBeNull()->and($r->close_status)->toBeNull()->and(DB::table('referral_fees')->count())->toBe(0);

    $r2 = ($this->newDeal)();
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r2->ulid}/close/dispute", ['reason' => 'Value looks wrong']);
    $this->actingAs($this->owner)->post("/app/disputes/close/{$r2->ulid}", ['confirm' => true, 'note' => 'Settlement statement checks out']);
    expect($r2->fresh()->close_status)->toBe('confirmed')->and(DB::table('referral_fees')->where('referral_id', $r2->id)->exists())->toBeTrue();
});

it('shows a team’s record: value only if they choose, an average only with enough ratings, public thanks only', function () {
    foreach (range(1, 3) as $i) {
        $r = ($this->newDeal)();
        app(Closing::class)->confirm($r, $this->a->leader);
        app(Closing::class)->rate($r->fresh(), $this->a->leader, $i === 3 ? 4 : 5, [], null);
        if ($i === 1) {
            app(Closing::class)->thank($r->fresh(), $this->b->leader, 'Thanks for a great referral!');
        }
    }
    $rec = ProfileStats::for($this->b);
    expect($rec['closed'])->toBe(3)->and($rec['value'])->toBeNull()->and($rec['rating'])->toBe(4.7)->and($rec['ratings'])->toBe(3);
    expect(ProfileStats::for($this->a)['thanks'])->toBe([]);
    $first = App\Domains\Referrals\Referral::whereNotNull('thanks_note')->first();
    app(Closing::class)->setThanksPublic($first, $this->a->leader, true);
    expect(ProfileStats::for($this->a)['thanks'])->toBe([['from' => 'Receiver Co', 'note' => 'Thanks for a great referral!']]);
    $this->actingAs($this->b->leader)->post('/app/team', ['name' => 'Receiver Co', 'show_deal_value' => true]);
    expect(ProfileStats::for($this->b)['value'])->toEqual(300000.0);
    $this->get('/app/team/preview')->assertInertia(fn ($p) => $p->where('record.closed', 3));
});
