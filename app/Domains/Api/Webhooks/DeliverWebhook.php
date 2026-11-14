<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One delivery attempt. Retries are scheduled by Webhooks itself (next_attempt_at), not by the queue. */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        Webhooks::deliver($this->deliveryId);
    }
}
