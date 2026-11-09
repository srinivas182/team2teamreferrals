<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Devices\DeviceRecorder;
use App\Domains\Identity\MagicLink\MagicLinkBroker;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MagicLinkController
{
    public function __construct(private readonly MagicLinkBroker $broker, private readonly DeviceRecorder $devices) {}

    public function store(Request $request): Response
    {
        $email = (string) $request->validate(['email' => ['required', 'email']])['email'];
        $key = 'magic-link:'.strtolower($email).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in links requested. Try again in a few minutes.']);
        }
        RateLimiter::hit($key, 600);
        $this->broker->send($email);

        return Inertia::render('auth/MagicLinkSent', ['email' => $email, 'seo' => SeoData::private('Check your email')->toArray()]);
    }

    public function consume(Request $request, string $token): RedirectResponse
    {
        $user = $this->broker->consume($token);
        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => 'That sign-in link has expired or was already used. Request a new one.']);
        }

        // The link proves access to the inbox; accounts with 2FA still pass the second step unless this device is trusted.
        $device = $this->devices->find($user, $request);
        if ($user->hasTwoFactorEnabled() && ($device === null || ! $device->isTrustedForTwoFactor())) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('app.home'));
    }
}
