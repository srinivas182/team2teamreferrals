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
            $this->commands([SendTrialNotices::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('t2t:billing:trial-notices')->dailyAt('09:00')->onOneServer();
        });
    }
}
