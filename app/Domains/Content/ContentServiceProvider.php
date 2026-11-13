<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Content\Http\AdminContentController;
use App\Domains\Content\Http\PublicPageController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** The website: pages built from blocks, served only where the app has no route; Admin → Website to edit them. */
final class ContentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:content.manage'])->prefix('admin/content')->group(function (): void {
                Route::get('/', [AdminContentController::class, 'index'])->name('admin.content');
                Route::get('new', [AdminContentController::class, 'edit'])->name('admin.content.new');
                Route::post('/', [AdminContentController::class, 'save'])->name('admin.content.store');
                Route::post('redirects', [AdminContentController::class, 'redirectStore'])->name('admin.content.redirects');
                Route::post('redirects/{redirect}/delete', [AdminContentController::class, 'redirectDelete'])->whereNumber('redirect')->name('admin.content.redirects.delete');
                Route::get('{page}', [AdminContentController::class, 'edit'])->where('page', '[0-9A-Za-z]{26}')->name('admin.content.edit');
                Route::post('{page}', [AdminContentController::class, 'save'])->where('page', '[0-9A-Za-z]{26}')->name('admin.content.update');
                Route::post('{page}/{action}', [AdminContentController::class, 'act'])->where('page', '[0-9A-Za-z]{26}')->whereIn('action', ['publish', 'rollback', 'unpublish'])->name('admin.content.act');
            });
            Route::middleware(['web', 'noindex:always', 'auth'])->get('preview/{page}', [PublicPageController::class, 'preview'])->where('page', '[0-9A-Za-z]{26}')->name('content.preview');
            // Website pages and redirects: only where no app route matches.
            Route::fallback([PublicPageController::class, 'fallback'])->middleware('web')->name('content.page');
        });
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->call(fn () => Pages::publishDue())->name('content-publish')->everyMinute()->onOneServer());
    }
}
