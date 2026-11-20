<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Referral alerts go by text, so the portal needs a verified mobile number (while texting is on). */
final class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // With texting off there's no code to send, so the mobile isn't verified (alerts go by email and in the app).
        if ($user instanceof User && ! $user->hasVerifiedPhone() && \App\Support\Sms\Texting::on()) {
            return $request->expectsJson() ? abort(409, 'Verify your mobile number first.') : redirect()->route('verification.phone');
        }

        return $next($request);
    }
}
