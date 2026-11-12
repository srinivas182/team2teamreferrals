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
use App\Domains\Referrals\Dashboard\OwnerKpis;
use App\Domains\Referrals\Dashboard\Period;
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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    $this->mk = function (Group $g, int $c, string $name) use ($sv) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $sv->approve($sv->apply($g, $t, $this->cities[$c], 'L', null), null);
        DB::table('team_licenses')->insert(['team_id' => $t->id, 'industry_id' => $g->industry_id, 'state_id' => DB::table('states')->value('id'), 'number' => 'L'.$t->id, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);

        return $t;
    };
    [$this->a, $this->b] = [($this->mk)($this->g, 2, 'Lone Star Solar'), ($this->mk)($this->g, 1, 'Hurricane Sun')];
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $ans = collect(app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'])
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $refs = collect(range(1, 3))->map(fn ($i) => app(Referrals::class)->send($this->a->leader, $this->g, $this->cities[1], [...$ans, 'client_name' => "Maria Lopez{$i}", 'client_phone' => '+14355550'.(170 + $i)], true, null, true)['referral']);
    foreach ($refs as $r) {
        app(Referrals::class)->accept($r->fresh(), $this->b->leader);
        DB::table('referral_events')->insert(['referral_id' => $r->id, 'event' => 'missed_update', 'team_id' => $this->b->id, 'created_at' => now()]);
    }
    app(Pipeline::class)->move($refs[0]->fresh(), $this->b->leader, 'won', null, ['closing_date' => now()->toDateString(), 'closing_value' => 20000, 'earnings' => 2000]);
    app(Closing::class)->confirm($refs[0]->fresh(), $this->a->leader);
    $this->p = Period::from('last_30');
    $this->base = '/app/group/solar-installers-network/dashboard';
});

it('[2] shows the owner every network number, each equal to its records, owners only', function () {
    foreach (array_keys(OwnerKpis::KPIS) as $key) {
        $v = OwnerKpis::value($key, $this->g, $this->p);
        $rows = OwnerKpis::rows($key, $this->g, $this->p);
        $expected = match ($key) {
            'fees_overdue' => array_sum(array_column($rows, 'amount')),
            'conversion' => $rows === [] ? null : (int) round(100 * count(array_filter($rows, fn ($r) => $r['status'] === 'won')) / count($rows)),
            default => count($rows),
        };
        expect($v)->toBe($expected, $key);
    }
    expect(OwnerKpis::value('seats', $this->g, $this->p))->toBe(2)->and(OwnerKpis::value('referrals', $this->g, $this->p))->toBe(3)->and(OwnerKpis::value('breaches', $this->g, $this->p))->toBe(3);
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn ($p) => $p->component('app/group/Dashboard')->where('leaders.referrers.0.team', 'Lone Star Solar')->where('leaders.closers.0.team', 'Hurricane Sun'));
    $this->get($this->base.'/kpi/breaches')->assertInertia(fn ($p) => $p->has('rows', 3)->where('base', $this->base)->where('rows.0.status', 'missed update'));
    expect($this->get($this->base.'/kpi/referrals/export.csv')->streamedContent())->not->toContain('555-0')->not->toContain('Lopez1');
    $this->actingAs($this->b->leader)->get($this->base)->assertForbidden();
});

it('[10] ranks restricted networks by service only, with no fee card and no closers ranking', function () {
    $m = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $m->owners()->attach($this->owner->id);
    ($this->mk)($m, 0, 'Lakeside Lending');
    $this->actingAs($this->owner)->get('/app/group/mortgage-lenders-circle/dashboard')->assertInertia(fn ($p) => $p->where('group.restricted', true)
        ->where('cards', fn ($c) => ! collect($c)->contains('key', 'fees_overdue'))->where('leaders.basis', 'service')->where('leaders.closers', []));
});

it('[11] lists teams to check in with, with reasons, and sends one friendly check-in a week', function () {
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn ($p) => $p->where('checkIns.0.team', 'Hurricane Sun')->where('checkIns.0.reasons.0', '3 missed updates'));
    $this->post($this->base.'/check-in', ['team' => $this->b->id, 'message' => 'Hi! Anything we can help with?'])->assertSessionHas('status', 'checkin-sent');
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'network.checkin' && str_contains($n->body, 'Anything we can help'));
    $this->post($this->base.'/check-in', ['team' => $this->b->id, 'message' => 'Another one this week'])->assertSessionHasErrors('team');
    $outsider = Team::forLeader(User::factory()->create());
    $this->post($this->base.'/check-in', ['team' => $outsider->id, 'message' => 'Not in this network'])->assertNotFound();
    $this->get($this->base)->assertInertia(fn ($p) => $p->where('checkIns.0.checkedIn', now()->toDateString()));
});

it('makes the owner menu work: a single network opens directly, several ask which, others are refused', function () {
    $this->actingAs($this->owner);
    foreach (['' => '', 'dashboard' => '/dashboard', 'seat-map' => '/seat-map', 'applications' => '/applications', 'challenges' => '/challenges'] as $page => $to) {
        $this->get('/app/owner'.($page ? "/{$page}" : ''))->assertRedirect('/app/group/solar-installers-network'.$to);
    }
    $this->get('/app/owner/nonsense')->assertNotFound();
    Group::where('slug', 'mortgage-lenders-circle')->firstOrFail()->owners()->attach($this->owner->id);
    $this->get('/app/owner/dashboard')->assertInertia(fn ($p) => $p->component('app/group/Choose')->has('groups', 2));
    $this->actingAs($this->b->leader)->get('/app/owner/dashboard')->assertForbidden();
});
