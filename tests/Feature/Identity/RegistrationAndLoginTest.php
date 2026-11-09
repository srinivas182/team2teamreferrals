<?php

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('registers a member with a normalised mobile and texts a verification code', function () {
    $sms = fakeSms();

    $this->post('/register', [
        'first_name' => 'Jordan', 'last_name' => 'Hale', 'email' => 'Jordan@Example.com', 'phone' => '(435) 555-0119',
        'password' => 'a long enough password', 'password_confirmation' => 'a long enough password', 'terms' => true,
    ])->assertRedirect('/app');

    $user = User::where('email', 'jordan@example.com')->firstOrFail();
    expect($user->phone)->toBe('+14355550119')
        ->and($user->ulid)->toHaveLength(26)
        ->and($sms->sent)->toHaveCount(1)
        ->and($sms->lastCode())->toMatch('/^\d{6}$/');
    $this->assertAuthenticatedAs($user);
});

it('rejects short passwords, invalid phones and unaccepted terms', function () {
    fakeSms();
    $this->post('/register', [
        'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com', 'phone' => '123',
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors(['phone', 'password', 'terms']);
});

it('logs in with a password and lands on the portal', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);
    $this->get('/app')->assertOk()->assertInertia(fn ($page) => $page->component('app/Home')->has('checklist', 4));
    expect($user->fresh()->last_login_at)->not->toBeNull()->and($user->devices()->count())->toBe(1);
});

it('locks out repeated failed logins', function () {
    $user = User::factory()->create();
    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong password here']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])->assertStatus(429);
    $this->assertGuest();
});

it('keeps sign-in pages out of search results', function () {
    $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($page) => $page->component('auth/Login')->where('seo.robots', 'noindex, nofollow'));
    $this->get('/register')->assertOk()->assertInertia(fn ($page) => $page->component('auth/Register'));
});

it('signs out deactivated accounts', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->actingAs($user)->get('/app')->assertRedirect('/login');
    $this->assertGuest();
});
