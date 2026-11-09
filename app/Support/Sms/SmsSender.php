<?php

declare(strict_types=1);

namespace App\Support\Sms;

/** Sends a text message. Drivers (Twilio and others) are configured from settings in Sprint 5. */
interface SmsSender
{
    public function send(string $toE164, string $message): void;
}
