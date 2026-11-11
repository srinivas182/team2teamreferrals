<?php

declare(strict_types=1);

namespace App\Support\Sms;

/** Sends a WhatsApp message: free text inside the 24-hour window, or a pre-approved template (ContentSid) outside it. */
interface WhatsAppSender
{
    /** @param array<int|string, string> $variables template variables, keyed 1, 2, … (sent as {"1": …}) */
    public function send(string $toE164, ?string $body, ?string $templateSid = null, array $variables = []): ?string;
}
