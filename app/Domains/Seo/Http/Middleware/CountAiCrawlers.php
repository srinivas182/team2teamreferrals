<?php

declare(strict_types=1);

namespace App\Domains\Seo\Http\Middleware;

use App\Domains\Seo\Monitoring\AiVisits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Counts AI and search crawler visits to public pages (successful page loads only; never the portal or admin). */
final class CountAiCrawlers
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && $response->getStatusCode() < 400 && ! $request->is('app', 'app/*', 'admin', 'admin/*', 'build/*', 'og/*', 'map/*', 'embed/*')
            && ($bot = AiVisits::crawler((string) $request->userAgent())) !== null) {
            try {
                AiVisits::count('crawler', $bot, $request->getPathInfo());
            } catch (Throwable) {
                // Counting must never break a page.
            }
        }

        return $response;
    }
}
