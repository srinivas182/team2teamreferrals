<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marker: `->middleware('edge.cache')` opts a public route into CDN caching (applied by EdgeCache). */
final class EdgeCacheable
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
