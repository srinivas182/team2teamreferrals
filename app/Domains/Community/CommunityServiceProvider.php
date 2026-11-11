<?php

declare(strict_types=1);

namespace App\Domains\Community;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class CommunityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([SendFeedDigests::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('t2t:feed:digest')->dailyAt('07:30')->onOneServer());
    }
}
