<?php

declare(strict_types=1);

namespace App\Support\Sms;

/** Sends a text message and returns the provider's message id when it has one. */
interface SmsSender
{
    public function send(string $toE164, string $message): ?string;
}
