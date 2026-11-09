<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin area: staff and super admins only, and only with two-step sign-in on. */
final class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isStaff(), 403);

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('settings.security')->with('status', 'two-factor-required');
        }

        return $next($request);
    }
}
