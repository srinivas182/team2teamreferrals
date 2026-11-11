<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Invites\InviteRewards;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Profiles\ProfileLeadInvite;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Referrals;
use Illuminate\Auth\Events\Registered;
use Illuminate\Notifications\AnonymousNotifiable;
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
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->seats = app(Seats::class);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->inviter = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Red Rock Solar']);
    $this->seats->approve($this->seats->apply($this->g, $this->inviter, $this->cities[0], 'L', null), null);
    $this->ans = fn (Group $g) => collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $this->n = 0;
    // A new pro joins through the inviter's link, takes a seat, and (optionally) qualifies.
    $this->invitee = function (Team $inviter, Group $g, int $city, bool $verify = true, bool $accept = true): Team {
        $u = User::factory()->create();
        session(['invite_code' => $inviter->invite_code]);
        event(new Registered($u));
        $t = tap(Team::forLeader($u))->update(['name' => 'Invitee '.(++$this->n)]);
        $this->seats->approve($this->seats->apply($g, $t, $this->cities[$city], 'L', null), null);
        if ($verify) {
            $state = DB::table('states')->value('id');
            $id = DB::table('team_licenses')->insertGetId(['team_id' => $t->id, 'industry_id' => $g->industry_id, 'state_id' => $state, 'number' => 'L'.$this->n, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            app(App\Domains\Network\Licenses\Licenses::class)->decide($id, User::factory()->create(), true, null);
        }
        if ($accept) {
            $r = app(Referrals::class)->send($inviter->leader, $g, $this->cities[$city], [...($this->ans)($g), 'client_name' => 'Client '.$this->n, 'client_phone' => '+143555506'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
            app(Referrals::class)->accept($r->fresh(), $t->leader);
        }

        return $t;
    };
    $this->credits = fn () => (int) DB::table('billing_credits')->where(['owner_type' => 'team', 'owner_id' => $this->inviter->id])->sum('amount_cents');
});

it('remembers the inviter from the join link and ignores self-invites', function () {
    $this->get('/join/'.$this->inviter->invite_code)->assertRedirect('/register')->assertSessionHas('invited_by', 'Red Rock Solar');
    $this->get('/register')->assertInertia(fn ($p) => $p->where('invitedBy', 'Red Rock Solar'));
    InviteRewards::attach($this->inviter->leader, $this->inviter->invite_code);
    expect(DB::table('invite_signups')->count())->toBe(0);
});

it('rewards the inviter with one free month only once the invitee is verified and has accepted a referral', function () {
    $t = ($this->invitee)($this->inviter, $this->g, 1, verify: true, accept: false);
    expect(($this->credits)())->toBe(0)->and(DB::table('invite_signups')->value('status'))->toBe('joined');
    $r = app(Referrals::class)->send($this->inviter->leader, $this->g, $this->cities[1], [...($this->ans)($this->g), 'client_name' => 'Late Client', 'client_phone' => '+14355550777'], true, null, true)['referral'];
    app(Referrals::class)->accept($r->fresh(), $t->leader);
    expect(($this->credits)())->toBe(1000)->and(DB::table('invite_signups')->value('status'))->toBe('rewarded');
    Notification::assertSentTo($this->inviter->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'thanks to your invite') && str_contains($n->body, 'free month'));
    Artisan::call('t2t:performance'); // the daily sweep never pays twice
    expect(($this->credits)())->toBe(1000);
});

it('gives Tier B inviters the badge, never a free month', function () {
    $mortgage = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $lender = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Lakeside Lending']);
    $this->seats->approve($this->seats->apply($mortgage, $lender, $this->cities[0], 'L', null), null);
    ($this->invitee)($lender, $mortgage, 1);
    expect(DB::table('invite_signups')->value('status'))->toBe('badge')->and(DB::table('billing_credits')->count())->toBe(0);
    foreach ([2, 3] as $city) {
        ($this->invitee)($lender, $mortgage, $city);
    }
    expect(App\Domains\Referrals\ProfileStats::for($lender)['builder'])->toBeTrue();
});

it('stops free months at the yearly cap', function () {
    app(Settings::class)->set('invites.cap', '1');
    ($this->invitee)($this->inviter, $this->g, 1);
    ($this->invitee)($this->inviter, $this->g, 2);
    expect(($this->credits)())->toBe(1000)->and(DB::table('invite_signups')->orderBy('id')->pluck('status')->all())->toBe(['rewarded', 'capped']);
});

it('gives group owners a free month for every 5 new paid seats, once each', function () {
    $owner = User::factory()->create();
    $this->g->owners()->attach($owner->id);
    $add = function (int $n) {
        foreach (Seat::query()->where('group_id', $this->g->id)->whereNotNull('primary_team_id')->whereNotIn('id', DB::table('seat_events')->where('event', 'activated')->select('seat_id'))->limit($n)->get() as $s) {
            DB::table('seat_events')->insert(['seat_id' => $s->id, 'team_id' => $s->primary_team_id, 'event' => 'activated', 'created_at' => now()]);
        }
    };
    foreach (range(1, 4) as $c) {
        $t = Team::forLeader(User::factory()->create());
        $this->seats->approve($this->seats->apply($this->g, $t, $this->cities[$c], 'L', null), null);
    }
    $add(4);
    Artisan::call('t2t:performance');
    $ownerCredits = fn () => (int) DB::table('billing_credits')->where(['owner_type' => 'team', 'owner_id' => Team::forLeader($owner)->id])->sum('amount_cents');
    expect($ownerCredits())->toBe(0);
    $add(1);
    Artisan::call('t2t:performance');
    Artisan::call('t2t:performance');
    expect($ownerCredits())->toBe(1000);
});

it('keeps one leaderboard per network, counting joined and up-and-running separately', function () {
    $mortgage = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $this->seats->approve($this->seats->apply($mortgage, $this->inviter, $this->cities[2], 'L', null), null);
    ($this->invitee)($this->inviter, $this->g, 1);
    ($this->invitee)($this->inviter, $this->g, 3, verify: false, accept: false); // joined, not running yet
    $this->actingAs($this->inviter->leader)->get('/app/invites')->assertInertia(fn ($p) => $p->has('boards', 2)
        ->where('boards.1.network', 'Solar Installers Network')->where('boards.1.rows.0.joined', 2)->where('boards.1.rows.0.running', 1));
});

it('shows invites and the leaderboard, invites confirmed profile referrers, and lets admins change the rules', function () {
    ($this->invitee)($this->inviter, $this->g, 1);
    $this->actingAs($this->inviter->leader)->get('/app/invites')->assertInertia(fn ($p) => $p->component('app/Invites')->where('link', url('/join/'.$this->inviter->invite_code))
        ->where('signups.0.status', 'rewarded')->where('boards.0.network', 'Solar Installers Network')->where('boards.0.rows.0.team', 'Red Rock Solar')
        ->where('boards.0.rows.0.joined', 1)->where('boards.0.rows.0.running', 1)->where('boards.0.rows.0.me', true));

    auth()->logout();
    $slug = $this->inviter->fresh()->slug;
    $this->post("/t/{$slug}/refer", ['referrer_name' => 'Ana', 'referrer_email' => 'ana@example.com', 'client_name' => 'Tom Brennan', 'client_phone' => '4355550100', 'need' => 'Wants a solar quote soon.', 'consent' => true, 'started' => encrypt(now()->subMinute()->timestamp)]);
    $link = Notification::sent(new AnonymousNotifiable, App\Domains\Network\Profiles\ProfileLeadVerify::class)->first()->link;
    $this->get(parse_url($link, PHP_URL_PATH));
    expect(Notification::sent(new AnonymousNotifiable, ProfileLeadInvite::class)->first()->join)->toBe(url('/join/'.$this->inviter->invite_code));

    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin)->post('/admin/billing/settings', ['invite_reward_months' => 2, 'invite_cap' => 6, 'owner_per_seats' => 10])->assertSessionHas('status', 'billing-settings-saved');
    expect(InviteRewards::setting('reward_months'))->toBe(2)->and(InviteRewards::setting('cap'))->toBe(6)->and(InviteRewards::setting('owner_per_seats'))->toBe(10);
});
