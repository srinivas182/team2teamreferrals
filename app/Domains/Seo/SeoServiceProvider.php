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
        if ($this->app->runningInConsole()) {
            $this->commands([Audit\AuditCommand::class, Monitoring\VisibilityCommand::class]);
        }
        // Nightly: check every public page, its links and duplicates, and resubmit the sitemap to Google. Monthly:
        // ask the AI answer engines that are switched on (Sprint 43b).
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $s): void {
            $s->command('t2t:seo:audit --site --per-section=200 --trigger=nightly')->dailyAt('03:15')->onOneServer()->withoutOverlapping()
                ->when(fn () => (bool) config('seo.indexable'));
            $s->call(fn () => app(Monitoring\SearchConsole::class)->enabled() ? app(Monitoring\SearchConsole::class)->submit() : null)->dailyAt('03:45')->name('seo:search-console')->onOneServer();
            $s->command('t2t:aeo:check')->monthlyOn(1, '05:30')->onOneServer();
        });

        // Crawler endpoints need no session, cookies or CSRF; canonical redirects and security headers run globally.
        Route::group([], function (): void {
            Route::get('robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
            Route::get('sitemap.xml', [SeoController::class, 'sitemapIndex'])->name('seo.sitemap');
            Route::get('sitemaps/{key}.xml', [SeoController::class, 'sitemap'])->where('key', '[a-z0-9-]+')->name('seo.sitemap.part');
            Route::get('llms.txt', [SeoController::class, 'llms'])->name('seo.llms');
            Route::get('og/{payload}.{signature}.png', fn (string $payload, string $signature) => PageImage::serve($payload, $signature))
                ->where(['payload' => '[A-Za-z0-9_-]{8,1200}', 'signature' => '[a-f0-9]{16}'])->middleware('throttle:120,1')->name('seo.page-image');
            // A person arrived from an AI assistant's answer (sent by the browser, so cached pages count too).
            Route::post('ai-visit', function (\Illuminate\Http\Request $request) {
                $d = $request->validate(['path' => ['required', 'string', 'max:255', 'starts_with:/'], 'referrer' => ['nullable', 'string', 'max:500'], 'utm' => ['nullable', 'string', 'max:80']]);
                if (($source = Monitoring\AiVisits::assistant($d['referrer'] ?? null, $d['utm'] ?? null)) !== null && ! preg_match('#^/(app|admin)(/|$)#', $d['path'])) {
                    Monitoring\AiVisits::count('referral', $source, $d['path']);
                }

                return response()->noContent();
            })->middleware('throttle:30,1')->name('seo.ai-visit');
            Route::get('llms-full.txt', [SeoController::class, 'llmsFull'])->name('seo.llms-full');
            Route::middleware(['web', 'noindex:always', 'auth', \App\Domains\Identity\Http\Middleware\EnsureAccountIsActive::class, 'verified', \App\Domains\Identity\Http\Middleware\EnsureStaff::class, 'can:settings.manage'])
                ->prefix('admin')->name('admin.')->group(function (): void {
                    Route::get('seo', [Http\SeoAdminController::class, 'index'])->name('seo');
                    Route::post('seo/crawlers', [Http\SeoAdminController::class, 'crawlers'])->name('seo.crawlers');
                    Route::post('seo/check', [Http\SeoAdminController::class, 'runCheck'])->middleware('throttle:6,1')->name('seo.check');
                    Route::post('seo/search-console', [Http\SeoAdminController::class, 'searchConsole'])->name('seo.search-console');
                    Route::post('seo/search-console/submit', [Http\SeoAdminController::class, 'submitSitemap'])->middleware('throttle:6,1')->name('seo.search-console.submit');
                    Route::post('seo/engines', [Http\SeoAdminController::class, 'engines'])->name('seo.engines');
                    Route::post('seo/questions', [Http\SeoAdminController::class, 'addQuestion'])->name('seo.questions');
                    Route::post('seo/questions/{question}/toggle', [Http\SeoAdminController::class, 'toggleQuestion'])->whereNumber('question')->name('seo.questions.toggle');
                    Route::post('seo/visibility', [Http\SeoAdminController::class, 'runVisibility'])->middleware('throttle:3,1')->name('seo.visibility');
                });
            Route::get('{key}.txt', [SeoController::class, 'indexNowKey'])->where('key', '[a-f0-9]{32,128}')->name('seo.indexnow-key');
        });
    }
}
