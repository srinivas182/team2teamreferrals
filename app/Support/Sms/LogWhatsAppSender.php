<?php

declare(strict_types=1);

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/** Development: WhatsApp messages are written to the log, never sent. */
final class LogWhatsAppSender implements WhatsAppSender
{
    public function send(string $toE164, ?string $body, ?string $templateSid = null, array $variables = []): ?string
    {
        Log::info('WhatsApp (not sent)', ['to' => $toE164, 'body' => $body, 'template' => $templateSid, 'variables' => $variables]);

        return null;
    }
}
