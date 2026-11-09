<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Referral alerts go by text, so the portal needs a verified mobile number. */
final class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->hasVerifiedPhone()) {
            return $request->expectsJson() ? abort(409, 'Verify your mobile number first.') : redirect()->route('verification.phone');
        }

        return $next($request);
    }
}
