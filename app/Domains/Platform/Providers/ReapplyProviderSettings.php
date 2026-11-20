<?php

declare(strict_types=1);

namespace App\Domains\Platform\Providers;

use Laravel\Octane\Events\RequestReceived;

/** Octane: each request starts from the worker's boot-time config, so the saved providers are applied again. */
final class ReapplyProviderSettings
{
    public function handle(RequestReceived $event): void
    {
        ApplyProviderSettings::refresh($event->sandbox);
    }
}
