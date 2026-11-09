<?php

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\NewDeviceSignInNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Activity;

it('records sign-ins and failed attempts with IP and device', function () {
    $user = User::factory()->create();
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Version/17.5 Safari/605.1.15')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong password value']);
    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple']);

    $failed = Activity::where('event', 'sign-in-failed')->firstOrFail();
    expect($failed->properties['email'])->toBe($user->email)->and($failed->properties['device'])->toBe('Safari on macOS');
    $ok = Activity::where('event', 'signed-in')->firstOrFail();
    expect($ok->causer_id)->toBe($user->id)->and($ok->properties['ip'])->toBe('127.0.0.1');
});

it('records account changes but never secrets', function () {
    $user = User::factory()->create();
    $user->update(['first_name' => 'Jordan']);
    $user->update(['password' => 'another long password value']);
    $changes = Activity::where('log_name', 'accounts')->where('subject_id', $user->id)->get();
    expect($changes->pluck('properties')->toJson())->toContain('Jordan')->not->toContain('password');
});

it('shows and filters the audit log and exports CSV', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('staff');
    $admin->givePermissionTo('audit.view');
    activity('security')->event('two-factor-enabled')->log('Two-step sign-in turned on');
    activity('auth')->event('signed-in')->log('Signed in');

    $this->actingAs($admin)->get('/admin/audit?log=security')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/AuditLog')->has('entries', 1)->where('entries.0.event', 'two-factor-enabled'));
    $csv = $this->get('/admin/audit/export?log=auth')->assertOk()->streamedContent();
    expect($csv)->toContain('When (UTC)')->toContain('Signed in')->not->toContain('Two-step sign-in turned on');
    expect(Activity::where('event', 'audit-exported')->exists())->toBeTrue();
});

it('emails an alert for a new device but not the first ever sign-in', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple']);
    Notification::assertNothingSentTo($user);

    auth()->logout();
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36')
        ->withUnencryptedCookies([]) // fresh browser: no device cookie
        ->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple']);
    Notification::assertSentTo($user, NewDeviceSignInNotification::class, fn ($n) => $n->device === 'Chrome on Android');
});

it('secures the account from the alert link: sessions end, devices and passkeys go, reset email sent', function () {
    Notification::fake();
    $user = User::factory()->create();
    $user->devices()->create(['token_hash' => hash('sha256', 'x'), 'label' => 'Unknown', 'last_seen_at' => now()]);
    $oldHash = $user->password;
    $url = URL::temporarySignedRoute('account.secure', now()->addDays(7), ['user' => $user->ulid]);

    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('auth/SecureAccount')->where('done', false));
    $this->post($url)->assertOk()->assertInertia(fn ($p) => $p->component('auth/SecureAccount')->where('done', true));

    $user->refresh();
    expect($user->password)->not->toBe($oldHash)->and($user->devices()->count())->toBe(0);
    Notification::assertSentTo($user, Illuminate\Auth\Notifications\ResetPassword::class);
    expect(Activity::where('event', 'account-secured')->exists())->toBeTrue();
});

it('refuses unsigned secure-account links', function () {
    $user = User::factory()->create();
    $this->post("/account/{$user->ulid}/secure")->assertForbidden();
});
