<?php

/*
 * The simplification sprint: one inbox, a menu that fits the member's role, and the activation funnel in Admin.
 */

use App\Domains\Content\Demo\DemoWorkspace;
use App\Domains\Growth\Activation;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => Cache::flush());

function memberWithTeam(?string $email = null): array
{
    $u = User::factory()->create(['email' => $email ?? fake()->unique()->safeEmail(), 'email_verified_at' => now(), 'phone' => '+1435555'.random_int(1000, 9999), 'phone_verified_at' => now()]);

    return [$u, Team::forLeader($u)];
}

it('puts leads and drafts in the one inbox, counts them on its badge, and refreshes the count when one is handled', function () {
    [$u, $team] = memberWithTeam();
    $lead = DB::table('profile_leads')->insertGetId(['team_id' => $team->id, 'referrer_name' => 'Rae', 'referrer_email' => 'rae@example.com', 'client_name' => 'Cleo Client',
        'need' => 'A kitchen remodel this spring', 'consent_text' => 'Yes', 'status' => 'new', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('referral_drafts')->insert(['ulid' => strtolower((string) Str::ulid()), 'user_id' => $u->id, 'client_name' => 'Dana Draft', 'review' => '[]', 'status' => 'pending',
        'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($u)->get('/app/inbox')->assertOk()->assertInertia(fn ($p) => $p->component('app/referrals/Inbox')
        ->has('others', 2)->where('others', fn ($o) => collect($o)->pluck('kind')->sort()->values()->all() === ['draft', 'lead'])
        ->where('kinds', fn ($k) => collect($k)->firstWhere('kind', 'lead')['count'] === 1 && collect($k)->firstWhere('kind', 'draft')['count'] === 1)
        ->where('referralUnread.waiting', 2));
    // Marking the lead contacted takes it out of the inbox and off the badge straight away.
    $this->post("/app/profile-leads/{$lead}", ['status' => 'contacted'])->assertRedirect();
    $this->get('/app/inbox')->assertInertia(fn ($p) => $p->has('others', 1)->where('referralUnread.waiting', 1));
    // Someone else's leads and drafts never show.
    [$other] = memberWithTeam();
    $this->actingAs($other)->get('/app/inbox')->assertInertia(fn ($p) => $p->has('others', 0)->where('referralUnread.waiting', 0));
});

it('shows leaders the account pages and members only what they work with', function () {
    [$leader, $team] = memberWithTeam();
    $this->actingAs($leader)->get('/app')->assertInertia(fn ($p) => $p->where('teamRole', 'leader'));
    $member = User::factory()->create(['email_verified_at' => now(), 'phone' => '+14355550111', 'phone_verified_at' => now()]);
    DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->get('/app')->assertInertia(fn ($p) => $p->where('teamRole', 'member'));
    $this->post('/logout');
    $this->get('/')->assertInertia(fn ($p) => $p->where('teamRole', null));
});

it('measures activation from sign-up to first accepted referral, leaving staff, demo and load-test accounts out', function () {
    // Five teams: one at each step.
    [$a] = memberWithTeam();
    DB::table('users')->where('id', $a->id)->update(['phone_verified_at' => null]); // signed up only
    [, $b] = memberWithTeam(); // verified
    [, $c] = memberWithTeam();
    [, $d] = memberWithTeam();
    [, $e] = memberWithTeam();
    Illuminate\Support\Facades\Artisan::call('t2t:catalogue:sync');
    $group = (int) DB::table('groups')->orderBy('id')->value('id');
    deferForeignKeys(); // placeholder seat ids; only the steps matter here
    foreach ([$c, $d, $e] as $t) {
        DB::table('seat_applications')->insert(['group_id' => $group, 'team_id' => $t->id, 'seat_id' => 1, 'status' => 'approved', 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    deferForeignKeys();
    $ref = fn (Team $from, ?string $accepted) => DB::table('referrals')->insert(['ulid' => strtolower((string) Str::ulid()), 'group_id' => $group, 'seat_id' => 1, 'sender_team_id' => $from->id,
        'sender_user_id' => $from->leader_id, 'routed_to' => 'primary', 'consent_method' => 'verbal', 'status' => $accepted ? 'accepted' : 'sent', 'client_name' => 'X', 'answers' => '{}', 'consent_at' => now(), 'consent_text' => 'Yes', 'respond_by' => now()->addDay(),
        'accepted_at' => $accepted, 'created_at' => now(), 'updated_at' => now()]);
    $ref($d, null);
    $ref($e, now()->toDateTimeString());
    // Not counted: staff, the demo workspace and load-test members.
    [$staff] = memberWithTeam();
    $staff->assignRole(Permissions::STAFF);
    memberWithTeam('demo9@'.DemoWorkspace::DOMAIN);
    memberWithTeam('loadtest9@loadtest.invalid');

    $teams = Activation::teams(30);
    $funnel = collect(Activation::funnel($teams))->keyBy('key');
    expect(count($teams))->toBe(5)
        ->and($funnel->map(fn ($s) => $s['teams'])->all())->toBe(['signed_up' => 5, 'verified' => 4, 'seat' => 3, 'sent' => 2, 'accepted' => 1])
        ->and($funnel['accepted']['share'])->toBe(0.2);
    expect(Activation::stuck($teams))->toBe([]); // all started today
    $this->travel(8)->days();
    $stuck = collect(Activation::stuck(Activation::teams(30)));
    expect($stuck)->toHaveCount(4)->and($stuck->firstWhere('team', $d->id))->toMatchArray(['step' => 'Sent a first referral', 'next' => 'First referral accepted'])
        ->and($stuck->pluck('team'))->not->toContain($e->id);
    // Only for staff who can manage growth.
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::STAFF);
    $this->actingAs($admin)->get('/admin/growth/activation')->assertForbidden();
    $admin->givePermissionTo('growth.manage');
    $this->actingAs($admin)->get('/admin/growth/activation?days=30')->assertOk()->assertInertia(fn ($p) => $p->component('growth/Activation')->where('total', 5)->has('stuck', 4));
});
