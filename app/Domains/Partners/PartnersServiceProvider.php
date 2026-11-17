<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Seo\Sitemap\SitemapRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** The partner directory (Sprint 47): local businesses advertising on city pages for a flat fee. */
final class PartnersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(SitemapRegistry::class, fn (SitemapRegistry $r) => $r->register(new PartnersSitemapProvider));
        $this->routes();
    }

    private function routes(): void
    {
        $slug = '[a-z0-9-]+';
        // Public pages, cached at the edge for signed-out visitors and purged when a partner changes.
        Route::middleware(['web', 'module:partner_directory', 'edge.cache', 'throttle:120,1'])->group(function () use ($slug): void {
            Route::get('partners', [Http\PartnersController::class, 'index'])->name('partners.index');
            Route::get('partners/advertise', [Http\PartnersController::class, 'advertise'])->name('partners.advertise');
            Route::get('partners/{category}/{state}/{city}', [Http\PartnersController::class, 'city'])->where(['category' => $slug, 'state' => '[a-zA-Z]{2}', 'city' => $slug])->name('partners.city');
            Route::get('partner/{slug}', [Http\PartnersController::class, 'show'])->where('slug', $slug)->name('partners.show');
        });
        Route::middleware(['web', 'module:partner_directory', 'noindex:always'])->group(function () use ($slug): void {
            Route::post('partners/advertise', [Http\PartnersController::class, 'apply'])->middleware('throttle:5,60')->name('partners.apply');
            Route::post('partner/{slug}/enquire', [Http\PartnersController::class, 'enquire'])->where('slug', $slug)->middleware('throttle:5,60')->name('partners.enquire');
        });
        Route::middleware(['web', 'module:partner_directory', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', 'throttle:30,1'])->group(function () use ($slug): void {
            Route::post('partner/{slug}/endorse', [Http\PartnersController::class, 'endorse'])->where('slug', $slug)->name('partners.endorse');
            Route::delete('partner/{slug}/endorse', [Http\PartnersController::class, 'withdraw'])->where('slug', $slug)->name('partners.endorse.withdraw');
        });
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:partners.manage'])->prefix('admin/partners')->name('admin.partners.')->group(function (): void {
            Route::get('/', [Http\AdminPartnersController::class, 'index'])->name('index');
            Route::post('{partner}/{action}', [Http\AdminPartnersController::class, 'decide'])->where('partner', '[0-9a-z]{26}')->whereIn('action', ['approve', 'reject', 'suspend', 'reinstate'])->name('decide');
            Route::get('categories', [Http\AdminPartnersController::class, 'categories'])->name('categories');
            Route::post('categories', [Http\AdminPartnersController::class, 'saveCategory'])->name('categories.store');
            Route::put('categories/{category}', [Http\AdminPartnersController::class, 'saveCategory'])->whereNumber('category')->name('categories.update');
        });
    }
}
