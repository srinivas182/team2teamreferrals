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

        // Start loading assets for links as soon as the user shows intent.
        Vite::prefetch(concurrency: 3);
    }
}
