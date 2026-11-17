<?php

declare(strict_types=1);

namespace App\Domains\Content\Demo;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo accounts (…@demo.invalid) can look everywhere but only change the things a demo is for: referrals and their
 * pipeline, the feed and events, notifications, tours and Ally. Account, security, billing, team, API and integration
 * changes are refused, so one visitor can't lock the next one out.
 */
final class DemoGuard
{
    private const ALLOWED = ['logout', 'app/send*', 'app/referrals*', 'app/pipeline*', 'app/feed*', 'app/events*', 'app/notifications*', 'app/tours*', 'ally/*',
        'app/nurture*', 'app/introduce*', 'app/group/*/prospects*', 'app/group/*/messages*', 'help/*', 'partners/seen', 'visit'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $request->isMethodSafe() || ! str_ends_with((string) $user->email, '@'.DemoWorkspace::DOMAIN) || $request->is(...self::ALLOWED)) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'This isn’t available in the demo.'], 403);
        }

        return back()->with('status', 'demo-blocked');
    }
}
