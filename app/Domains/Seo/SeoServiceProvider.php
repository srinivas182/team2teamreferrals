<?php

declare(strict_types=1);

namespace App\Domains\Seo;

use App\Domains\Seo\Events\PublicUrlsChanged;
use App\Domains\Seo\Http\SeoController;
use App\Domains\Seo\Listeners\NotifySearchEngines;
use App\Domains\Seo\Sitemap\SitemapRegistry;
use App\Domains\Seo\Sitemap\StaticPagesProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SitemapRegistry::class, function (): SitemapRegistry {
            $registry = new SitemapRegistry;
            $registry->register(new StaticPagesProvider);

            return $registry;
        });
    }

    public function boot(): void
    {
        Event::listen(PublicUrlsChanged::class, NotifySearchEngines::class);

        // Crawler endpoints need no session, cookies or CSRF; canonical redirects and security headers run globally.
        Route::group([], function (): void {
            Route::get('robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
            Route::get('sitemap.xml', [SeoController::class, 'sitemapIndex'])->name('seo.sitemap');
            Route::get('sitemaps/{key}.xml', [SeoController::class, 'sitemap'])->where('key', '[a-z0-9-]+')->name('seo.sitemap.part');
            Route::get('llms.txt', [SeoController::class, 'llms'])->name('seo.llms');
            Route::get('{key}.txt', [SeoController::class, 'indexNowKey'])->where('key', '[a-f0-9]{32,128}')->name('seo.indexnow-key');
        });
    }
}
