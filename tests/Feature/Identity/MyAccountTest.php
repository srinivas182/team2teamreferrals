<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\ConfirmNewEmailNotification;
use App\Domains\Identity\Notifications\EmailChangedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function accountUser(): User
{
    return User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '+14355550119', 'password' => Hash::make('a-long-password-1'), 'timezone' => 'America/Denver']);
}

it('shows My account with the person’s details, and links it from the menus and search', function () {
    $this->actingAs(accountUser())->get('/app/settings/account')->assertOk()->assertInertia(fn (Assert $p) => $p->component('app/settings/Account')
        ->where('account.email', 'pat@example.com')->where('account.phone', '+14355550119')->where('account.timezone', 'America/Denver')
        ->has('timezones', 7));
    auth()->logout();
    $this->get('/app/settings/account')->assertRedirect('/login');
});

it('saves name, mobile and time zone straight away, logged in the audit trail', function () {
    $u = accountUser();
    $this->actingAs($u)->put('/app/settings/account', ['first_name' => 'Patricia', 'last_name' => 'Lee-Ray', 'phone' => '(435) 555-0120', 'timezone' => 'America/Chicago'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'account-updated');
    $u->refresh();
    expect($u->first_name)->toBe('Patricia')->and($u->phone)->toBe('+14355550120')->and($u->timezone)->toBe('America/Chicago')
        // A new number is unconfirmed until texted a code (texting is off here, so nothing asks for one yet).
        ->and($u->phone_verified_at)->toBeNull();
    expect(Spatie\Activitylog\Models\Activity::query()->where('subject_id', $u->id)->where('event', 'updated')->latest('id')->first()?->properties['attributes'])
        ->toMatchArray(['first_name' => 'Patricia', 'phone' => '+14355550120']);

    // Bad input is explained.
    $this->put('/app/settings/account', ['first_name' => '', 'last_name' => 'Lee', 'phone' => '123', 'timezone' => 'Mars/Base'])->assertSessionHasErrors(['first_name', 'phone', 'timezone']);
});

it('confirms a new mobile by text before it’s used, when texting is on', function () {
    textingOn();
    $u = accountUser();
    $this->actingAs($u)->put('/app/settings/account', ['first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '4355550121', 'timezone' => 'America/Denver'])
        ->assertRedirect(route('verification.phone'));
    expect($u->fresh()->phone_verified_at)->toBeNull();
});

it('keeps a confirmed mobile confirmed when the number isn’t changed', function () {
    textingOn();
    $v = User::factory()->create(['phone' => '+14355550122']);
    $this->actingAs($v)->put('/app/settings/account', ['first_name' => 'Al', 'last_name' => 'Ray', 'phone' => '+14355550122', 'timezone' => 'America/Denver'])
        ->assertSessionHas('status', 'account-updated');
    expect($v->fresh()->phone_verified_at)->not->toBeNull();
});

it('changes the email only when the link sent to the new address is clicked, and tells the old address', function () {
    Notification::fake();
    $u = accountUser();
    User::factory()->create(['email' => 'taken@example.com']);

    // Needs the password, a free address, and a different one.
    $this->actingAs($u)->post('/app/settings/account/email', ['email' => 'new@example.com', 'password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post('/app/settings/account/email', ['email' => 'taken@example.com', 'password' => 'a-long-password-1'])->assertSessionHasErrors('email');
    $this->post('/app/settings/account/email', ['email' => 'pat@example.com', 'password' => 'a-long-password-1'])->assertSessionHasErrors('email');

    $this->post('/app/settings/account/email', ['email' => 'new@example.com', 'password' => 'a-long-password-1'])->assertSessionHas('status', 'email-change-sent');
    expect($u->fresh()->email)->toBe('pat@example.com'); // nothing changes yet
    $link = null;
    Notification::assertSentTo(new AnonymousNotifiable, ConfirmNewEmailNotification::class, function ($n, $channels, $to) use (&$link) {
        $link = $n->link;

        return $to->routes['mail'] === 'new@example.com';
    });

    // A tampered link is refused.
    $this->get(str_replace('new%40example.com', 'evil%40example.com', $link))->assertForbidden();

    // Opened on another device, signed out: still works.
    auth()->logout();
    $this->get($link)->assertRedirect(route('login'));
    expect($u->fresh()->email)->toBe('new@example.com')->and($u->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertSentTo(new AnonymousNotifiable, EmailChangedNotification::class, fn ($n, $c, $to) => $to->routes['mail'] === 'pat@example.com' && $n->newEmail === 'new@example.com');

    // Used once: the account no longer has the address the link was sent from.
    $this->get($link)->assertStatus(410);
});

it('lets staff use My account too', function () {
    $admin = User::factory()->create(['phone' => null]);
    $admin->assignRole(App\Domains\Identity\Authorization\Permissions::SUPER_ADMIN);
    $this->actingAs($admin)->get('/app/settings/account')->assertOk();
    $this->put('/app/settings/account', ['first_name' => 'Chris', 'last_name' => 'Admin', 'phone' => '', 'timezone' => 'America/New_York'])->assertSessionHasNoErrors();
    expect($admin->fresh()->phone)->toBeNull();
});
