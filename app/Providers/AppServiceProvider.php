<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Virus scanning for uploads: ClamAV when configured, otherwise nothing (files then wait unless scanning is optional).
        $this->app->singleton(\App\Support\Scanning\VirusScanner::class, fn () => filled(config('attachments.clamav_host'))
            ? new \App\Support\Scanning\ClamdScanner((string) config('attachments.clamav_host'), (int) config('attachments.clamav_port'))
            : new \App\Support\Scanning\NoScanner);
        // Debug tools are installed as dev dependencies and only ever registered locally.
        if ($this->app->environment('local')) {
            foreach (['Laravel\\Telescope\\TelescopeServiceProvider', 'Barryvdh\\Debugbar\\ServiceProvider'] as $provider) {
                if (class_exists($provider)) {
                    $this->app->register($provider);
                }
            }
        }
    }

    public function boot(): void
    {
        // Catch lazy-loading, silently discarded attributes and missing attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Error reports identify the member only by their public ULID, never by name or email.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Authenticated::class, function ($e): void {
            $ulid = $e->user instanceof \App\Domains\Identity\Models\User ? $e->user->ulid : null;
            if ($ulid !== null) {
                \Sentry\configureScope(fn (\Sentry\State\Scope $scope) => $scope->setUser(['id' => $ulid]));
                \Illuminate\Support\Facades\Context::add('user', $ulid);
            }
        });

        // Slow-query log: anything over 500 ms is written with its SQL and the request ID.
        if (! $this->app->runningUnitTests()) {
            \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $q): void {
                if ($q->time >= (float) config('logging.slow_query_ms', 500)) {
                    \Illuminate\Support\Facades\Log::warning('Slow query', ['ms' => $q->time, 'sql' => $q->sql, 'connection' => $q->connectionName]);
                }
            });
        }

        // Prefetching every page's scripts after load is set per request (HandleInertiaRequests): portal and admin
        // only, so public visitors on phones don't download the whole app.
        // Public pages arrive fully rendered from the server, so on a phone the stylesheet and text come first: their
        // scripts are fetched at low priority instead of competing with them (they still load right after, for
        // interactivity). Keeps Largest Contentful Paint under 2.5 s on mobile (Sprint 43). The portal and admin keep
        // full preloading: they are apps.
        Vite::usePreloadTagAttributes(fn (?string $src, string $url) => str_ends_with($url, '.js') && ! request()->is('app', 'app/*', 'admin', 'admin/*') ? ['rel' => 'prefetch'] : []);
    }
}
