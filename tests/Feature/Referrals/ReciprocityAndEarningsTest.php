<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Closing;
use App\Domains\Referrals\Credits;
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $this->a = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Red Rock Solar']);
    $this->b = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    $s->approve($s->apply($this->g, $this->a, $this->cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $this->cities[1], 'L', null), null);
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $this->ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
        $this->ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->n = 0;
    $this->deal = function (bool $won = false) {
        $this->n++;
        $r = app(Referrals::class)->send($this->a->leader, $this->g->fresh(), $this->cities[1], [...$this->ans, 'client_name' => "Client {$this->n}", 'client_phone' => '+143555504'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
        app(Referrals::class)->accept($r->fresh(), $this->b->leader);
        if ($won) {
            app(Pipeline::class)->move($r->fresh(), $this->b->leader, 'won', null, ['closing_date' => '2026-12-20', 'closing_value' => 100000, 'earnings' => 5000]);
            app(Closing::class)->confirm($r->fresh(), $this->a->leader);
        }

        return $r->fresh();
    };
    // The balance worked out independently, straight from the referral records.
    $this->fromRecords = function (Team $t) {
        $sentAccepted = Referral::where('sender_team_id', $t->id)->whereNotNull('accepted_at')->count();
        $won = Referral::where('sender_team_id', $t->id)->whereIn('close_status', ['confirmed', 'auto_confirmed'])->count();
        $received = Referral::where('receiver_team_id', $t->id)->whereNotNull('accepted_at')->count();

        return $sentAccepted * 1 + $won * 2 - $received;
    };
});

it('credits giving and debits getting, once each, matching the referral records exactly', function () {
    ($this->deal)();
    $r = ($this->deal)(won: true);
    Credits::onAccepted($r); // a repeated hook never double-counts
    Credits::onWonConfirmed($r);
    expect(Credits::balance($this->a))->toBe(4)->and(Credits::balance($this->b))->toBe(-2)
        ->and(Credits::balance($this->a))->toBe(($this->fromRecords)($this->a))->and(Credits::balance($this->b))->toBe(($this->fromRecords)($this->b))
        ->and(Credits::giveGet($this->a))->toBe(['given' => 2, 'received' => 0, 'balance' => 4]);
});

it('uses each network’s own credit rates', function () {
    $this->g->update(['rules' => [...$this->g->rules, 'credit_sent' => 3, 'credit_closed' => 5, 'credit_received' => 0]]);
    ($this->deal)(won: true);
    expect(Credits::balance($this->a))->toBe(8)->and(Credits::balance($this->b))->toBe(0);
});

it('flags teams below the minimum balance only when the owner switches it on', function () {
    foreach (range(1, 6) as $i) {
        ($this->deal)();
    }
    expect(Credits::flagged($this->g->fresh()))->toBe([]);
    $this->g->update(['rules' => [...$this->g->rules, 'min_balance_enabled' => true, 'min_balance' => -5]]);
    expect(Credits::flagged($this->g->fresh()))->toBe([['team' => 'Hurricane Sun', 'balance' => -6]]);
    $this->actingAs($this->owner)->get('/app/group/solar-installers-network')->assertInertia(fn ($p) => $p->where('group.flaggedTeams.0.team', 'Hurricane Sun'));
});

it('shows earnings net of platform fees that match the fee records', function () {
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/fee', ['type' => 'percent', 'value' => 30, 'due_days' => 10]);
    (new App\Domains\Network\Industries\Models\PlatformFeeRule)->forceFill(['industry_id' => $this->g->industry_id, 'group_id' => null, 'mode' => 'percent', 'percent' => 10, 'charge_at' => 'closing', 'enabled' => true, 'enabled_at' => now()])->save();
    ($this->deal)(won: true);
    ($this->deal)(won: true);
    $fees = DB::table('referral_fees')->orderBy('id')->get();
    $this->actingAs($this->b->leader)->post("/app/earnings/{$fees[0]->id}/paid");
    $this->actingAs($this->a->leader)->post("/app/earnings/{$fees[0]->id}/received");
    $this->travel(11)->days();

    $this->get('/app/earnings')->assertInertia(fn ($p) => $p->where('dashboard.receivedYtd', 1350)->where('dashboard.receivedAll', 1350)
        ->where('dashboard.expected', 1350)->where('dashboard.overdue', 1350)->where('dashboard.byGroup.0.label', 'Solar Installers Network')
        ->where('dashboard.byPartner.0.label', 'Hurricane Sun')->where('dashboard.byPartner.0.received', 1350)->where('giveGet.balance', 6));
    $net = (int) DB::table('referral_fees')->where(['payee_team_id' => $this->a->id, 'status' => 'received'])->selectRaw('sum(amount_cents - platform_fee_cents) as n')->value('n');
    expect($net)->toBe(135000);

    $csv = $this->get('/app/earnings/export.csv?month='.now()->year)->assertOk()->getContent();
    expect(substr_count((string) $csv, "\n"))->toBe(3);
    $this->get('/app/earnings/'.now()->year.'.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
});
