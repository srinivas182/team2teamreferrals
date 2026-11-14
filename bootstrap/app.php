<?php

use App\Domains\Seo\Http\Middleware\CanonicalUrl;
use App\Domains\Seo\Http\Middleware\NoIndex;
use App\Domains\Seo\SeoData;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth']])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(CanonicalUrl::class);
        $middleware->appendToGroup('web', [HandleInertiaRequests::class, NoIndex::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(App\Domains\Seo\Http\Middleware\CountAiCrawlers::class); // which pages AI crawlers read (Sprint 43b)
        $middleware->alias([
            'noindex' => NoIndex::class,
            'role' => Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
            'module' => App\Domains\Platform\Http\Middleware\RequireModule::class,
            'edge.cache' => App\Domains\Platform\Http\Middleware\EdgeCacheable::class,
            'throttle' => App\Http\Middleware\ThrottlePerRoute::class, // one bucket per route and limit
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->prepend(App\Http\Middleware\AssignRequestId::class);
        // Lets "sign out of other devices" invalidate those sessions.
        $middleware->appendToGroup('web', Illuminate\Session\Middleware\AuthenticateSession::class);
        // Admin-controlled maintenance page for members (staff and webhooks pass through).
        $middleware->appendToGroup('web', App\Domains\Platform\Http\Middleware\MaintenanceGate::class);
        // Outermost in the web group so it sees (and can drop) the session cookies on edge-cacheable pages.
        $middleware->prependToGroup('web', App\Domains\Platform\Http\Middleware\EdgeCache::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/app');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Branded, noindex error pages. 500s keep Laravel's debug page while debugging.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            $status = $response->getStatusCode();
            // An address that moved (a renamed post, page or group) redirects wherever the page that used it lives.
            if ($status === 404 && $request->isMethod('GET') && ($moved = Illuminate\Support\Facades\DB::table('redirects')->where('from_path', '/'.trim($request->path(), '/'))->first()) !== null) {
                Illuminate\Support\Facades\DB::table('redirects')->where('id', $moved->id)->increment('hits');

                return redirect((string) $moved->to_path, (int) $moved->code);
            }
            // A page removed for good says so (410), so search engines drop it instead of retrying (Sprint 43b).
            if ($status === 404 && $request->isMethod('GET') && App\Domains\Seo\Monitoring\GoneUrls::isGone($request->getPathInfo())) {
                $status = 410;
            }
            $branded = in_array($status, [403, 404, 410, 419, 503], true) || ($status === 500 && ! config('app.debug'));
            if (! $branded || $request->expectsJson()) {
                return $response;
            }
            $seo = (new SeoData(title: 'Error '.$status, description: 'Team to Team Referrals', index: false))->toArray();

            return Inertia::render('Error', ['status' => $status, 'seo' => $seo, 'requestId' => $request->attributes->get('request_id')])->toResponse($request)->setStatusCode($status);
        });
    })->create();
