<?php

/*
 * Two-step sign-in for admin staff: required by default; a super admin can turn it off for a demo (STAFF_TWO_FACTOR
 * or Admin → System → General) and back on before going live.
 */

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\StaffTwoFactor;
use App\Domains\Platform\Settings\Settings;

beforeEach(fn () => app(Settings::class)->flush());

function staffMember(string $role = Permissions::STAFF): User
{
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($role);

    return $u;
}

it('requires two-step sign-in for admin staff by default', function () {
    expect(StaffTwoFactor::required())->toBeTrue();
    $this->actingAs(staffMember(Permissions::SUPER_ADMIN))->get('/admin')->assertRedirect(route('settings.security'));
});

it('lets a demo turn it off with STAFF_TWO_FACTOR=false, and a saved setting wins either way', function () {
    config(['app.staff_two_factor' => false]);
    $admin = staffMember(Permissions::SUPER_ADMIN);
    $this->actingAs($admin)->get('/admin')->assertOk();

    app(Settings::class)->set('security.staff_two_factor', '1');
    $this->actingAs($admin)->get('/admin')->assertRedirect(route('settings.security'));
});

it('lets only a super admin change it, from Admin → System → General', function () {
    config(['app.staff_two_factor' => false]);
    $staff = staffMember();
    $staff->givePermissionTo('settings.manage');
    $this->actingAs($staff)->put('/admin/system/staff-two-factor', ['required' => true])->assertForbidden();

    $owner = staffMember(Permissions::SUPER_ADMIN);
    $this->actingAs($owner)->get('/admin/system/general')->assertInertia(fn ($p) => $p->where('staffTwoFactor', ['required' => false, 'canEdit' => true]));
    // Turned on: from the next request the admin area asks for two-step sign-in.
    $this->actingAs($owner)->put('/admin/system/staff-two-factor', ['required' => true])->assertRedirect();
    expect(StaffTwoFactor::required())->toBeTrue();
    $this->actingAs($owner)->get('/admin')->assertRedirect(route('settings.security'));
});
