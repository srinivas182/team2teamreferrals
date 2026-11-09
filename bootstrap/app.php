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
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(CanonicalUrl::class);
        $middleware->appendToGroup('web', [HandleInertiaRequests::class, NoIndex::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'noindex' => NoIndex::class,
            'role' => Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
            'module' => App\Domains\Platform\Http\Middleware\RequireModule::class,
        ]);
        $middleware->trustProxies(at: '*');
        // Lets "sign out of other devices" invalidate those sessions.
        $middleware->appendToGroup('web', Illuminate\Session\Middleware\AuthenticateSession::class);
        // Admin-controlled maintenance page for members (staff and webhooks pass through).
        $middleware->appendToGroup('web', App\Domains\Platform\Http\Middleware\MaintenanceGate::class);
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
            $branded = in_array($status, [403, 404, 419, 503], true) || ($status === 500 && ! config('app.debug'));
            if (! $branded || $request->expectsJson()) {
                return $response;
            }
            $seo = (new SeoData(title: 'Error '.$status, description: 'Team to Team Referrals', index: false))->toArray();

            return Inertia::render('Error', ['status' => $status, 'seo' => $seo])->toResponse($request)->setStatusCode($status);
        });
    })->create();
