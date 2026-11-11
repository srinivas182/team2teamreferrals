<?php

declare(strict_types=1);

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TwilioWhatsAppSender implements WhatsAppSender
{
    public function __construct(private readonly string $sid, private readonly string $token, private readonly string $from) {}

    public function send(string $toE164, ?string $body, ?string $templateSid = null, array $variables = []): ?string
    {
        $payload = ['To' => 'whatsapp:'.$toE164, 'From' => 'whatsapp:'.$this->from];
        $payload += $templateSid !== null ? ['ContentSid' => $templateSid, 'ContentVariables' => (string) json_encode($variables)] : ['Body' => (string) $body];
        $res = Http::withBasicAuth($this->sid, $this->token)->asForm()->timeout(15)->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", $payload);
        if ($res->failed()) {
            throw new RuntimeException('Twilio WhatsApp refused the message: '.($res->json('message') ?? $res->status()));
        }

        return is_string($res->json('sid')) ? $res->json('sid') : null;
    }
}
