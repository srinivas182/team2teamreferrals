<?php

use App\Domains\Identity\Devices\DeviceRecorder;
use App\Domains\Identity\Models\User;
use PragmaRX\Google2FA\Google2FA;

function twoFactorUser(): array
{
    $g = new Google2FA;
    $secret = $g->generateSecretKey();
    $user = User::factory()->create(['two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one-1234'])), 'two_factor_confirmed_at' => now()]);

    return [$user, $g, $secret];
}

it('challenges members with two-step sign-in and remembers the device when asked', function () {
    [$user, $g, $secret] = twoFactorUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => $g->getCurrentOtp($secret), 'remember_device' => true])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);

    $device = $user->devices()->firstOrFail();
    expect($device->isTrustedForTwoFactor())->toBeTrue();
});

it('skips the second step on a trusted device', function () {
    [$user] = twoFactorUser();
    $token = 'trusted-device-token-for-tests-0123456789abcdef';
    $user->devices()->create(['token_hash' => hash('sha256', $token), 'label' => 'Chrome on macOS', 'last_seen_at' => now(), 'two_factor_trusted_until' => now()->addDays(10)]);

    $this->withCookie(DeviceRecorder::COOKIE, $token)
        ->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
});

it('protects the security page with a password confirmation and lists devices', function () {
    $user = User::factory()->create();
    $user->devices()->create(['token_hash' => hash('sha256', 'x'), 'label' => 'Safari on iPhone', 'last_seen_at' => now()]);

    $this->actingAs($user)->get('/app/settings/security')->assertRedirect('/user/confirm-password');
    $this->withSession(['auth.password_confirmed_at' => time()])->get('/app/settings/security')->assertOk()
        ->assertInertia(fn ($p) => $p->component('app/settings/Security')->has('devices', 1)->where('twoFactor.enabled', false));
});

it('removes only the member’s own devices', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = $user->devices()->create(['token_hash' => hash('sha256', 'a'), 'label' => 'A', 'last_seen_at' => now()]);
    $theirs = $other->devices()->create(['token_hash' => hash('sha256', 'b'), 'label' => 'B', 'last_seen_at' => now()]);

    $this->actingAs($user)->delete("/app/settings/security/devices/{$theirs->id}")->assertNotFound();
    $this->delete("/app/settings/security/devices/{$mine->id}")->assertRedirect();
    expect($user->devices()->count())->toBe(0)->and($other->devices()->count())->toBe(1);
});

it('signs out other devices after the password is confirmed', function () {
    $user = User::factory()->create();
    $user->devices()->create(['token_hash' => hash('sha256', 'old'), 'label' => 'Old laptop', 'last_seen_at' => now()]);
    $this->actingAs($user)->post('/app/settings/security/other-devices', ['password' => 'nope'])->assertSessionHasErrors('password');
    $this->post('/app/settings/security/other-devices', ['password' => 'correct horse battery staple'])->assertSessionHas('status', 'other-devices-signed-out');
    expect($user->devices()->count())->toBe(0);
});

it('offers passkey sign-in options', function () {
    $this->getJson('/passkeys/login/options')->assertOk()->assertJsonStructure(['options' => ['challenge']]);
});
