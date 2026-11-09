<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;
use LogicException;

/** A password-reset token used as a "set your password" link for invited staff. */
final class PasswordSetupToken
{
    public static function for(User $user): string
    {
        $broker = Password::broker();
        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('The default password broker cannot create tokens.');
        }

        return $broker->createToken($user);
    }
}
