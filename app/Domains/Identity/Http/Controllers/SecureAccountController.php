<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\SecureAccount;
use App\Domains\Identity\Models\User;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Reached from the "This wasn't me" button in new-device emails (signed, 7 days). */
final class SecureAccountController
{
    public function show(Request $request, User $user): Response
    {
        return Inertia::render('auth/SecureAccount', [
            'action' => $request->fullUrl(),
            'email' => $user->email,
            'done' => false,
            'seo' => SeoData::private('Secure your account')->toArray(),
        ]);
    }

    public function store(Request $request, User $user, SecureAccount $secure): Response
    {
        $secure($user);
        if (Auth::id() === $user->getKey()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return Inertia::render('auth/SecureAccount', ['action' => null, 'email' => $user->email, 'done' => true, 'seo' => SeoData::private('Account secured')->toArray()]);
    }
}
