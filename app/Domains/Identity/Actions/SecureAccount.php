<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "This wasn't me": locks out whoever else has access. Rotating the password ends every
 * session (AuthenticateSession checks the password hash); devices and passkeys are removed;
 * the owner gets a reset link to choose a new password.
 */
final class SecureAccount
{
    public function __invoke(User $user): void
    {
        $user->forceFill(['password' => Str::password(48), 'remember_token' => Str::random(60)])->save();
        $user->devices()->delete();
        $user->passkeys()->delete();
        Password::broker()->sendResetLink(['email' => $user->email]);
        activity('security')->causedBy($user)->performedOn($user)->event('account-secured')->log('Account secured after an unrecognised sign-in: sessions ended, devices and passkeys removed, reset link sent');
    }
}
