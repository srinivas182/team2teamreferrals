<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Identity\Phone\PhoneVerifier;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PhoneVerificationController
{
    public function __construct(private readonly PhoneVerifier $verifier) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->user($request);
        if ($user->hasVerifiedPhone()) {
            return redirect()->intended(route('app.home'));
        }

        return Inertia::render('auth/VerifyPhone', [
            'phone' => $user->phone ? PhoneNumber::masked($user->phone) : null,
            'status' => session('status'),
            'seo' => SeoData::private('Verify your mobile')->toArray(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $key = 'phone-otp-send:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['code' => 'Too many codes requested. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
        }
        RateLimiter::hit($key, 600);
        $this->verifier->send($user);

        return back()->with('status', 'code-sent');
    }

    public function sendByEmail(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $key = 'phone-otp-send:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['code' => 'Too many codes requested. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
        }
        RateLimiter::hit($key, 600);
        $this->verifier->sendByEmail($user);

        return back()->with('status', 'code-emailed');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']], ['code.digits' => 'Enter the 6-digit code from the text message.']);
        if (! $this->verifier->verify($this->user($request), $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired. Check the text, or send a new code.']);
        }

        return redirect()->intended(route('app.home'))->with('status', 'phone-verified');
    }

    public function update(Request $request): RedirectResponse
    {
        $phone = PhoneNumber::normalize((string) $request->input('phone'));
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid US mobile number.']);
        }
        $user = $this->user($request);
        $user->forceFill(['phone' => $phone, 'phone_verified_at' => null])->save();

        return $this->send($request);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
