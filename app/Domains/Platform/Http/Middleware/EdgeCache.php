<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a CDN cache public marketing pages for signed-out visitors: shared cache for 5 minutes,
 * served stale for up to a day while it refreshes, purged automatically when content changes.
 * Never applies to signed-in users, non-GET requests, Inertia JSON visits or error responses;
 * session cookies are not set on these responses (they carry no personal data).
 *
 * Runs as the outermost web middleware, because the session layer adds its cookies on the way out;
 * routes opt in by listing the `edge.cache` marker middleware.
 */
final class EdgeCache
{
    public function handle(Request $request, Closure $next, int $seconds = 300): Response
    {
        $response = $next($request);
        $route = $request->route();
        if (! $route instanceof \Illuminate\Routing\Route || ! in_array('edge.cache', $route->gatherMiddleware(), true)) {
            return $response;
        }
        $cacheable = $request->isMethod('GET') && $request->user() === null && ! $request->header('X-Inertia') && $response->getStatusCode() === 200;
        if (! $cacheable) {
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }
        $response->headers->set('Cache-Control', "public, max-age=0, s-maxage={$seconds}, stale-while-revalidate=86400");
        $response->headers->set('Vary', 'Accept-Encoding');
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }
}
