<?php

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\MagicLinkNotification;
use Illuminate\Support\Facades\Notification;

it('emails a single-use sign-in link that logs the member in', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/login/magic', ['email' => $user->email])->assertOk()->assertInertia(fn ($p) => $p->component('auth/MagicLinkSent'));

    $url = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $n) use (&$url) {
        $url = $n->url;

        return true;
    });
    $this->get($url)->assertRedirect('/app');
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $this->get($url)->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('gives the same answer for unknown emails and sends nothing', function () {
    Notification::fake();
    $this->post('/login/magic', ['email' => 'nobody@example.com'])->assertOk()->assertInertia(fn ($p) => $p->component('auth/MagicLinkSent'));
    Notification::assertNothingSent();
});

it('rejects tampered links', function () {
    $this->get('/login/magic/forged-token?signature=abc&expires='.now()->addMinutes(5)->timestamp)->assertForbidden();
});

it('still asks for two-step sign-in when the member has it on', function () {
    Notification::fake();
    $user = User::factory()->create(['two_factor_secret' => encrypt('SECRETSECRETSECR'), 'two_factor_confirmed_at' => now()]);
    $this->post('/login/magic', ['email' => $user->email]);
    $url = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function ($n) use (&$url) {
        $url = $n->url;

        return true;
    });
    $this->get($url)->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
});
