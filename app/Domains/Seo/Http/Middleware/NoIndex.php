<?php

declare(strict_types=1);

namespace App\Domains\Seo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps private areas (portal, admin, auth, client links) and all non-production sites out of search results. */
final class NoIndex
{
    public function handle(Request $request, Closure $next, string $force = ''): Response
    {
        $response = $next($request);
        if ($force === 'always' || ! config('seo.indexable')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
