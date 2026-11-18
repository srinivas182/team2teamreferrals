<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's plain "throttle:N,M" counts every throttled route in one shared bucket per visitor, so ordinary browsing
 * (profile views, widget loads) used up a strict limit such as 5 referrals an hour. This keeps one bucket per route
 * and limit, so each limit means what it says. Named limiters and explicit prefixes behave as before.
 */
final class ThrottlePerRoute extends ThrottleRequests
{
    /**
     * Load generators on staging (LOAD_TEST_IPS) aren't limited, so a load test measures the servers rather than the
     * per-visitor limits. Never applies in production.
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        $ips = array_filter(array_map('trim', explode(',', (string) config('app.load_test_ips'))));
        if ($ips !== [] && ! app()->environment('production') && in_array($request->ip(), $ips, true)) {
            return $next($request);
        }

        // Pass on exactly what was given: Laravel tells a named limiter ("throttle:api") apart by the number of arguments.
        return parent::handle($request, $next, ...array_slice(func_get_args(), 2));
    }

    /** @param \Illuminate\Http\Request $request */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route instanceof \Illuminate\Routing\Route ? ($route->getName() ?? $route->uri()) : '';

        return sha1($scope.'|'.parent::resolveRequestSignature($request));
    }
}
