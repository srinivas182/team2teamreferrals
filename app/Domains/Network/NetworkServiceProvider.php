<?php

declare(strict_types=1);

namespace App\Domains\Network;

use App\Domains\Network\Locations\Console\ImportLocations;
use App\Domains\Network\Locations\Console\ImportMetros;
use Illuminate\Support\ServiceProvider;

/** The network's structure: locations now; industries, groups and seats in the next sprints. */
final class NetworkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $s): void {
            $s->command('t2t:groups:scheduled')->dailyAt('06:00')->onOneServer();
            // Hourly so 72-hour offers pass on promptly; grace and release use whole days.
            $s->command('t2t:seats:lifecycle')->hourly()->onOneServer();
            $s->command('t2t:licenses:reminders')->dailyAt('07:00')->onOneServer();
        });
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([ImportLocations::class, ImportMetros::class, Industries\Catalogue\SyncCatalogue::class, Groups\ApplyScheduledChanges::class, Seats\RunSeatLifecycle::class, Licenses\SendLicenseReminders::class]);
        }
    }
}
