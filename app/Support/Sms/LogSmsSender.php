<?php

declare(strict_types=1);

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes messages to the log instead of sending them. */
final class LogSmsSender implements SmsSender
{
    public function send(string $toE164, string $message): void
    {
        Log::info('SMS to '.$toE164.': '.$message);
    }
}
