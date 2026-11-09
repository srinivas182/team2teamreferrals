<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Phone\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        $input['phone'] = PhoneNumber::normalize((string) ($input['phone'] ?? '')) ?? ($input['phone'] ?? null);

        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class)],
            'phone' => ['required', 'string', 'regex:/^\+1[2-9]\d{2}[2-9]\d{6}$/'],
            'password' => PasswordRules::rules(),
            'terms' => ['accepted'],
        ], [
            'first_name.required' => 'Enter your first name.',
            'last_name.required' => 'Enter your last name.',
            'email.required' => 'Enter your work email.',
            'email.email' => 'Enter a valid email address, like you@brokerage.com.',
            'email.unique' => 'An account already uses this email. Log in instead, or reset your password.',
            'phone.required' => 'Enter your mobile number.',
            'phone.regex' => 'Enter a valid US mobile number.',
            'password.required' => 'Choose a password.',
            'password.confirmed' => 'The passwords don’t match.',
            'terms.accepted' => 'Please accept the terms to continue.',
        ])->validate();

        return User::create([
            'first_name' => trim((string) $input['first_name']),
            'last_name' => trim((string) $input['last_name']),
            'email' => strtolower(trim((string) $input['email'])),
            'phone' => $input['phone'],
            'password' => (string) $input['password'],
        ]);
    }
}
