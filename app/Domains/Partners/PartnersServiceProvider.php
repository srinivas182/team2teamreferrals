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
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $s): void {
            $s->call(fn () => PartnerBilling::run())->dailyAt('06:20')->name('partners:billing')->withoutOverlapping()->onOneServer();
        });
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
            // Views and clicks from the browser (sendBeacon carries no CSRF header; nothing here changes an account).
            Route::post('partners/seen', [Http\PartnersController::class, 'seen'])->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)->middleware('throttle:60,1')->name('partners.seen');
        });
        // The partner account: signed in by an emailed link.
        Route::middleware(['web', 'module:partner_directory', 'noindex:always'])->prefix('partner-portal')->name('partners.portal.')->group(function (): void {
            $p = '[0-9a-z]{26}';
            Route::get('/', [Http\PartnerPortalController::class, 'home'])->name('home');
            Route::post('link', [Http\PartnerPortalController::class, 'link'])->middleware('throttle:5,10')->name('link');
            Route::get('enter', [Http\PartnerPortalController::class, 'enter'])->middleware(['signed', 'throttle:20,10'])->name('enter');
            Route::post('logout', [Http\PartnerPortalController::class, 'logout'])->middleware('throttle:30,1')->name('logout');
            Route::middleware('throttle:60,1')->group(function () use ($p): void {
                Route::get('{partner}', [Http\PartnerPortalController::class, 'show'])->where('partner', $p)->name('show');
                Route::post('{partner}/details', [Http\PartnerPortalController::class, 'details'])->where('partner', $p)->name('details');
                Route::post('{partner}/cities', [Http\PartnerPortalController::class, 'addCities'])->where('partner', $p)->name('cities');
                Route::delete('{partner}/cities/{listing}', [Http\PartnerPortalController::class, 'removeCity'])->where('partner', $p)->whereNumber('listing')->name('cities.remove');
                Route::post('{partner}/setup-intent', [Http\PartnerPortalController::class, 'setupIntent'])->where('partner', $p)->middleware('throttle:20,10')->name('setup-intent');
                Route::post('{partner}/card', [Http\PartnerPortalController::class, 'saveCard'])->where('partner', $p)->middleware('throttle:20,10')->name('card');
                Route::post('{partner}/invoices/{invoice}/pay', [Http\PartnerPortalController::class, 'payNow'])->where('partner', $p)->whereNumber('invoice')->middleware('throttle:10,10')->name('pay');
                Route::get('{partner}/invoices/{invoice}.pdf', [Http\PartnerPortalController::class, 'invoicePdf'])->where('partner', $p)->whereNumber('invoice')->name('invoice');
                Route::post('{partner}/cycle', [Http\PartnerPortalController::class, 'cycle'])->where('partner', $p)->name('cycle');
                Route::post('{partner}/plan/{action}', [Http\PartnerPortalController::class, 'plan'])->where('partner', $p)->whereIn('action', ['cancel', 'resume', 'restart'])->name('plan');
                Route::get('{partner}/stats.csv', [Http\PartnerPortalController::class, 'statsCsv'])->where('partner', $p)->middleware('throttle:20,10')->name('stats');
            });
        });
        Route::middleware(['web', 'module:partner_directory', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', 'throttle:30,1'])->group(function () use ($slug): void {
            Route::post('partner/{slug}/endorse', [Http\PartnersController::class, 'endorse'])->where('slug', $slug)->name('partners.endorse');
            Route::delete('partner/{slug}/endorse', [Http\PartnersController::class, 'withdraw'])->where('slug', $slug)->name('partners.endorse.withdraw');
            Route::get('app/partners', [Http\MemberPartnersController::class, 'index'])->name('partners.member');
            Route::post('app/partners/{slug}/share', [Http\MemberPartnersController::class, 'share'])->where('slug', $slug)->middleware('throttle:20,60')->name('partners.share');
        });
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:partners.manage'])->prefix('admin/partners')->name('admin.partners.')->group(function (): void {
            Route::get('/', [Http\AdminPartnersController::class, 'index'])->name('index');
            Route::post('{partner}/{action}', [Http\AdminPartnersController::class, 'decide'])->where('partner', '[0-9a-z]{26}')->whereIn('action', ['approve', 'reject', 'suspend', 'reinstate', 'approve-changes', 'reject-changes'])->name('decide');
            Route::post('settings', [Http\AdminPartnersController::class, 'settings'])->name('settings');
            Route::get('categories', [Http\AdminPartnersController::class, 'categories'])->name('categories');
            Route::post('categories', [Http\AdminPartnersController::class, 'saveCategory'])->name('categories.store');
            Route::put('categories/{category}', [Http\AdminPartnersController::class, 'saveCategory'])->whereNumber('category')->name('categories.update');
        });
    }
}
