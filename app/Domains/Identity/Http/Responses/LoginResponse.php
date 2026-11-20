<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Responses;

use App\Domains\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * After signing in: back to the page that asked for it, otherwise staff go to Admin and members to their portal.
 * (Staff don't need the portal's mobile verification, so sending them to the portal first would stop them there.)
 */
final class LoginResponse implements LoginResponseContract, TwoFactorLoginResponse
{
    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }
        $user = $request->user();
        $home = $user instanceof User && $user->isStaff() ? '/admin' : (string) config('fortify.home');

        return redirect()->intended($home);
    }
}
