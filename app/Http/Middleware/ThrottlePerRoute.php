<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's plain "throttle:N,M" counts every throttled route in one shared bucket per visitor, so ordinary browsing
 * (profile views, widget loads) used up a strict limit such as 5 referrals an hour. This keeps one bucket per route
 * and limit, so each limit means what it says. Named limiters and explicit prefixes behave as before.
 */
final class ThrottlePerRoute extends ThrottleRequests
{
    /** @param \Illuminate\Http\Request $request */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route instanceof \Illuminate\Routing\Route ? ($route->getName() ?? $route->uri()) : '';

        return sha1($scope.'|'.parent::resolveRequestSignature($request));
    }
}
