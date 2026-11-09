<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Messaging\Push\PushSender;
use App\Domains\Messaging\Push\WebPushSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Notifications: catalogue, preferences, templates, in-app, email, text and push, bundling and digests. */
final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushSender::class, WebPushSender::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([FlushOutbox::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('notifications:flush')->everyMinute()->withoutOverlapping()->onOneServer());
    }
}
