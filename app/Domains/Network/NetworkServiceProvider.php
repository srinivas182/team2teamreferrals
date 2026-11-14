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
        $this->callAfterResolving(\App\Domains\Seo\Sitemap\SitemapRegistry::class, fn ($r) => $r->register(new Prelaunch\PrelaunchSitemapProvider));
        $this->callAfterResolving(\App\Domains\Seo\Sitemap\SitemapRegistry::class, fn ($r) => $r->register(new Profiles\ProfileSitemapProvider));
        $this->callAfterResolving(\App\Domains\Seo\Sitemap\SitemapRegistry::class, fn ($r) => $r->register(new Directory\DirectorySitemapProvider));
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $s): void {
            $s->command('t2t:groups:scheduled')->dailyAt('06:00')->onOneServer();
            // Hourly so 72-hour offers pass on promptly; grace and release use whole days.
            $s->command('t2t:seats:lifecycle')->hourly()->onOneServer();
            $s->command('t2t:licenses:reminders')->dailyAt('07:00')->onOneServer();
            $s->command('t2t:onboarding:nudges')->dailyAt('15:00')->onOneServer();
            $s->command('t2t:training:reminders')->dailyAt('15:30')->onOneServer();
            // Every 15 minutes, so a missed 24-hour deadline moves the client to the backup promptly.
            $s->command('t2t:referrals:expire')->everyFifteenMinutes()->onOneServer();
            $s->command('t2t:performance')->dailyAt('08:00')->onOneServer();
        });
        // Public directory pages follow their data: a seat, team or group change refreshes the cached pages.
        Seats\Models\Seat::saved(fn (Seats\Models\Seat $s) => \Illuminate\Support\Facades\DB::afterCommit(fn () => Directory\DirectoryUrls::seatChanged((int) $s->id)));
        Seats\Models\Seat::deleting(function (Seats\Models\Seat $s): void {
            Directory\NetworkMap::forget((int) $s->group_id);
            $paths = Directory\DirectoryUrls::forSeat((int) $s->id);
            \Illuminate\Support\Facades\DB::afterCommit(fn () => $paths !== [] ? event(new \App\Domains\Seo\Events\PublicUrlsChanged($paths)) : null);
        });
        Seats\Models\Team::updated(function (Seats\Models\Team $t): void {
            if ($t->wasChanged(['name', 'slug', 'logo_path', 'profile_public', 'bio', 'founded_year'])) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => Directory\DirectoryUrls::teamChanged((int) $t->id));
            }
        });
        Groups\Models\Group::saved(function (Groups\Models\Group $g): void {
            Directory\NetworkMap::forget((int) $g->id);
            \Illuminate\Support\Facades\DB::afterCommit(fn () => Directory\DirectoryUrls::groupChanged((string) $g->slug));
        });
        // The network map shows waitlists too: joining or leaving one refreshes it.
        foreach (['saved', 'deleted'] as $e) {
            Seats\Models\SeatWaitlist::$e(fn (Seats\Models\SeatWaitlist $w) => \Illuminate\Support\Facades\DB::afterCommit(fn () => Directory\DirectoryUrls::seatChanged((int) $w->seat_id)));
        }
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([ImportLocations::class, ImportMetros::class, Industries\Catalogue\SyncCatalogue::class, Groups\ApplyScheduledChanges::class, Seats\RunSeatLifecycle::class, Licenses\SendLicenseReminders::class, Onboarding\SendOnboardingNudges::class, Training\SendTrainingReminders::class, \App\Domains\Referrals\ExpireReferrals::class, \App\Domains\Referrals\Performance\PerformanceJobs::class]);
        }
    }
}
