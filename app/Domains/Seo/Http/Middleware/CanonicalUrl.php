<?php

declare(strict_types=1);

namespace App\Domains\Seo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One URL per page: 301-redirects other hosts or schemes to the canonical origin and
 * removes trailing slashes, so ranking signals are never split across duplicates.
 * Paths are not lower-cased because private tokens (e.g. /c/{token}) are case-sensitive.
 */
final class CanonicalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('seo.force_canonical') || ! $request->isMethod('GET')) {
            return $next($request);
        }
        $canonical = parse_url((string) config('seo.canonical_url'));
        $scheme = $canonical['scheme'] ?? 'https';
        $host = $canonical['host'] ?? $request->getHost();
        $path = $request->getPathInfo();
        $trimmed = $path !== '/' ? rtrim($path, '/') : $path;

        if ($request->getScheme() !== $scheme || $request->getHost() !== $host || $trimmed !== $path) {
            $query = $request->getQueryString();

            return redirect()->away($scheme.'://'.$host.$trimmed.($query ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
