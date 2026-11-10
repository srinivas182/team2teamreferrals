<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Support\Scanning\VirusScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class ScanAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $attachmentId) {}

    public function handle(VirusScanner $scanner): void
    {
        Attachments::scan($this->attachmentId, $scanner);
    }
}
