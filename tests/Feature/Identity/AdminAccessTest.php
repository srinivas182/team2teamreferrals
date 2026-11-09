<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

function staff(array $permissions = [], bool $twoFactor = true, string $role = Permissions::STAFF): User
{
    $user = User::factory()->create($twoFactor ? ['two_factor_secret' => encrypt('SECRETSECRETSECR'), 'two_factor_confirmed_at' => now()] : []);
    $user->assignRole($role);
    $user->givePermissionTo($permissions);

    return $user;
}

it('keeps members out of the admin', function () {
    $member = User::factory()->create();
    $member->assignRole(Permissions::MEMBER);
    $this->actingAs($member)->get('/admin')->assertForbidden();
});

it('sends staff without two-step sign-in to set it up first', function () {
    $this->actingAs(staff(twoFactor: false))->get('/admin')->assertRedirect('/app/settings/security')->assertSessionHas('status', 'two-factor-required');
});

it('lets staff with two-step sign-in into the admin but only permitted screens', function () {
    $user = staff(['audit.view']);
    $this->actingAs($user)->get('/admin')->assertOk()->assertInertia(fn ($p) => $p->component('admin/Dashboard')->where('auth.user.permissions', ['audit.view']));
    $this->get('/admin/audit')->assertOk();
    $this->get('/admin/staff')->assertForbidden();
});

it('gives super admins every permission', function () {
    $this->actingAs(staff(role: Permissions::SUPER_ADMIN))->get('/admin/staff')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/staff/Index')->where('auth.user.permissions', Permissions::all()));
});

it('invites staff with chosen permissions and logs it', function () {
    Notification::fake();
    $admin = staff(['staff.manage']);

    $this->actingAs($admin)->post('/admin/staff', ['first_name' => 'Priya', 'last_name' => 'Raman', 'email' => 'priya@example.com', 'permissions' => ['licenses.verify', 'members.manage']])
        ->assertSessionHas('status', 'staff-invited');

    $invited = User::where('email', 'priya@example.com')->firstOrFail();
    expect($invited->hasRole(Permissions::STAFF))->toBeTrue()
        ->and($invited->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe(['licenses.verify', 'members.manage']);
    Notification::assertSentTo($invited, StaffInvitationNotification::class);
    expect(Activity::where('event', 'staff-invited')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('rejects unknown permissions', function () {
    $this->actingAs(staff(['staff.manage']))->post('/admin/staff', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'x@example.com', 'permissions' => ['everything']])
        ->assertSessionHasErrors('permissions.0');
});

it('changes permissions with a before-and-after audit entry', function () {
    $admin = staff(['staff.manage']);
    $target = staff(['licenses.verify']);
    $this->actingAs($admin)->put("/admin/staff/{$target->ulid}", ['permissions' => ['audit.view']])->assertSessionHas('status', 'permissions-saved');

    expect($target->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['audit.view']);
    $entry = Activity::where('event', 'permissions-changed')->latest('id')->firstOrFail();
    expect($entry->properties['old'])->toBe(['licenses.verify'])->and($entry->properties['attributes'])->toBe(['audit.view']);
});

it('stops staff changing themselves or super admins', function () {
    $admin = staff(['staff.manage']);
    $super = staff(role: Permissions::SUPER_ADMIN);
    $this->actingAs($admin)->put("/admin/staff/{$admin->ulid}", ['permissions' => ['audit.view']])->assertForbidden();
    $this->put("/admin/staff/{$super->ulid}", ['permissions' => []])->assertForbidden();
    $this->patch("/admin/staff/{$super->ulid}/active")->assertForbidden();
});

it('deactivates staff, which signs them out on their next request', function () {
    $admin = staff(['staff.manage']);
    $target = staff();
    $this->actingAs($admin)->patch("/admin/staff/{$target->ulid}/active")->assertSessionHas('status', 'staff-deactivated');
    expect($target->fresh()->is_active)->toBeFalse();
    $this->actingAs($target->fresh())->get('/admin')->assertRedirect('/login');
});

it('verifies an invited email when the password is set', function () {
    Notification::fake();
    $admin = staff(['staff.manage']);
    $this->actingAs($admin)->post('/admin/staff', ['first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@example.com', 'permissions' => []]);
    $invited = User::where('email', 'sam@example.com')->firstOrFail();
    $token = null;
    Notification::assertSentTo($invited, StaffInvitationNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });
    auth()->logout();
    $this->post('/reset-password', ['token' => $token, 'email' => 'sam@example.com', 'password' => 'a brand new long password', 'password_confirmation' => 'a brand new long password'])->assertRedirect();
    expect($invited->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('bootstraps a super admin from the command line', function () {
    Notification::fake();
    Artisan::call('t2t:super-admin', ['email' => 'Owner@Example.com', '--first' => 'Srini', '--last' => 'Owner']);
    $owner = User::where('email', 'owner@example.com')->firstOrFail();
    expect($owner->hasRole(Permissions::SUPER_ADMIN))->toBeTrue()->and($owner->first_name)->toBe('Srini');
    Notification::assertSentTo($owner, StaffInvitationNotification::class);
});

it('gives new members the member role', function () {
    fakeSms();
    $this->post('/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'm@example.com', 'phone' => '(435) 555-0119', 'password' => 'a long enough password', 'password_confirmation' => 'a long enough password', 'terms' => true]);
    expect(User::where('email', 'm@example.com')->firstOrFail()->hasRole(Permissions::MEMBER))->toBeTrue();
});
