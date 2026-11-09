<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /**
     * At least 12 characters and not found in known data breaches
     * (checked with k-anonymity: the password itself never leaves the server).
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        $rule = Password::min(12);
        if (! app()->environment('testing')) {
            $rule = $rule->uncompromised();
        }

        return ['required', 'string', $rule, 'confirmed'];
    }
}
