<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Teams\TeamInviteNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Notification::fake());

it('starts every pro as the leader of a team of one, and lets them edit its profile', function () {
    Storage::fake('public');
    $u = User::factory()->create();
    $this->actingAs($u)->get('/app/team')->assertOk()->assertInertia(fn ($p) => $p->component('app/Team')->where('role', 'leader')->where('canManage', true)->has('members', 1));

    $this->post('/app/team', ['name' => 'SunPath Solar', 'bio' => 'Residential solar since 2012.', 'website' => 'https://sunpath.example', 'service_area' => 'Austin and Travis County', 'founded_year' => 2012,
        'social' => ['linkedin' => 'https://linkedin.com/company/sunpath', 'myspace' => 'https://x.example'], 'logo' => UploadedFile::fake()->createWithContent('l.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))])
        ->assertSessionHas('status', 'team-saved');
    $t = Team::of($u);
    expect($t->name)->toBe('SunPath Solar')->and($t->social)->toBe(['linkedin' => 'https://linkedin.com/company/sunpath'])->and($t->logo_path)->not->toBeNull();
    $this->post('/app/team', ['name' => 'X', 'founded_year' => 1800])->assertSessionHasErrors('founded_year');
});

it('invites members by email, who join with the invited email only', function () {
    $leader = User::factory()->create();
    $this->actingAs($leader)->post('/app/team/invites', ['email' => 'Mia@Example.com', 'role' => 'assistant'])->assertSessionHas('status', 'team-invite-sent');
    $url = Notification::sent(new AnonymousNotifiable, TeamInviteNotification::class)->first()->url;
    $path = parse_url($url, PHP_URL_PATH);

    auth()->logout();
    $this->get($path)->assertOk()->assertInertia(fn ($p) => $p->component('TeamInvitation')->where('invite.role', 'assistant'))->assertSessionHas('url.intended');

    $this->actingAs(User::factory()->create())->post($path.'/accept')->assertSessionHasErrors('invite');
    $mia = User::factory()->create(['email' => 'mia@example.com']);
    $this->actingAs($mia)->post($path.'/accept')->assertRedirect('/app/team');
    $team = Team::of($leader);
    expect($team->roleOf($mia))->toBe('assistant')->and(Team::of($mia)?->id)->toBe($team->id);
    $this->post($path.'/accept')->assertSessionHasErrors('invite');
});

it('lets assistants edit the profile but only the leader invite or remove members', function () {
    $leader = User::factory()->create();
    $team = Team::forLeader($leader);
    $asst = User::factory()->create();
    $member = User::factory()->create();
    DB::table('team_members')->insert([['team_id' => $team->id, 'user_id' => $asst->id, 'role' => 'assistant', 'created_at' => now(), 'updated_at' => now()], ['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]]);

    $this->actingAs($asst)->post('/app/team', ['name' => 'Renamed'])->assertSessionHas('status', 'team-saved');
    $this->post('/app/team/invites', ['email' => 'a@example.com', 'role' => 'member'])->assertForbidden();
    $this->actingAs($member)->post('/app/team', ['name' => 'Nope'])->assertForbidden();
    $this->get('/app/team')->assertInertia(fn ($p) => $p->where('canEdit', false)->where('invites', []));

    $this->actingAs($leader)->delete("/app/team/members/{$member->ulid}")->assertSessionHas('status', 'team-member-removed');
    expect($team->roleOf($member))->toBeNull();
    $this->delete("/app/team/members/{$leader->ulid}")->assertSessionHasErrors('member');
});

it('expires team invitations after 14 days and refuses inviting existing members', function () {
    $leader = User::factory()->create();
    $this->actingAs($leader)->post('/app/team/invites', ['email' => $leader->email, 'role' => 'member'])->assertSessionHasErrors('email');
    $this->post('/app/team/invites', ['email' => 'late@example.com', 'role' => 'member']);
    $path = parse_url(Notification::sent(new AnonymousNotifiable, TeamInviteNotification::class)->first()->url, PHP_URL_PATH);
    $this->travel(15)->days();
    $this->get($path)->assertInertia(fn ($p) => $p->where('invite', null));
});
