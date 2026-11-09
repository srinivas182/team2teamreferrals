<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request gets an ID: returned in X-Request-Id, attached to every log line and error report,
 * and shown on error pages so support can find the exact request in seconds.
 * A valid incoming ID (e.g. from the load balancer) is kept so one request traces end to end.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id');
        $id = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) ? $incoming : (string) Str::ulid();
        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);
        \Sentry\configureScope(fn (\Sentry\State\Scope $scope) => $scope->setTag('request_id', $id));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
