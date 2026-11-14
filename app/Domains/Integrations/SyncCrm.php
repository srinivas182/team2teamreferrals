<?php

declare(strict_types=1);

namespace App\Domains\Integrations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One CRM sync. Retries are scheduled by CrmSync (next_attempt_at), not by the queue. */
final class SyncCrm implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $logId) {}

    public function handle(): void
    {
        CrmSync::run($this->logId);
    }
}
