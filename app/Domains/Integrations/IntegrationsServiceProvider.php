<?php

declare(strict_types=1);

namespace App\Domains\Integrations;

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Referrals\Events\ReferralEventRecorded;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** CRM connectors (Sprint 45a): routes, the referral listener and the schedule. */
final class IntegrationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ReferralEventRecorded::class, fn (ReferralEventRecorded $e) => CrmSync::onTimeline($e->referral, $e->event));

        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->prefix('app/integrations')->name('integrations.')->group(function (): void {
            Route::get('/', [Http\IntegrationsController::class, 'index'])->name('index');
            Route::middleware('password.confirm')->group(function (): void {
                Route::post('/', [Http\IntegrationsController::class, 'connect'])->middleware('throttle:10,1')->name('connect');
                Route::patch('{integration}', [Http\IntegrationsController::class, 'update'])->name('update');
                Route::post('{integration}/test', [Http\IntegrationsController::class, 'test'])->middleware('throttle:10,1')->name('test');
                Route::post('{integration}/pause', [Http\IntegrationsController::class, 'pause'])->name('pause');
                Route::post('{integration}/retry', [Http\IntegrationsController::class, 'retry'])->middleware('throttle:10,1')->name('retry');
                Route::delete('{integration}', [Http\IntegrationsController::class, 'disconnect'])->name('disconnect');
            });
        });
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:settings.manage'])->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('integrations', [Http\AdminIntegrationsController::class, 'index'])->name('integrations');
            Route::post('integrations', [Http\AdminIntegrationsController::class, 'save'])->name('integrations.save');
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->call(fn () => CrmSync::retryDue())->everyMinute()->name('integrations:retries')->withoutOverlapping()->onOneServer();
            $s->call(fn () => CrmSync::pollStages())->everyFiveMinutes()->name('integrations:stages')->withoutOverlapping()->onOneServer();
            $s->call(fn () => CrmSync::prune())->dailyAt('04:35')->name('integrations:log-cleanup')->onOneServer();
        });
    }
}
