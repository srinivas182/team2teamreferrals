<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([SendTrialNotices::class, RunBilling::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('t2t:billing:trial-notices')->dailyAt('09:00')->onOneServer();
            // The 1st: last month's invoices; every other day: due retries only.
            $s->command('t2t:billing:run')->monthlyOn(1, '06:00')->onOneServer();
            $s->command('t2t:billing:run --retries')->dailyAt('10:00')->when(fn () => now()->day !== 1)->onOneServer();
        });
    }
}
