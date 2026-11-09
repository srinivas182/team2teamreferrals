<?php

declare(strict_types=1);

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Twilio Programmable Messaging over its REST API, with delivery receipts posted back to us. */
final class TwilioSmsSender implements SmsSender
{
    public function __construct(private readonly string $sid, private readonly string $token, private readonly string $from) {}

    public function send(string $toE164, string $message): ?string
    {
        $payload = ['To' => $toE164, 'Body' => $message, 'StatusCallback' => URL::route('webhooks.twilio.status')];
        $payload[str_starts_with($this->from, 'MG') ? 'MessagingServiceSid' : 'From'] = $this->from;

        $response = Http::asForm()->withBasicAuth($this->sid, $this->token)->timeout(10)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Twilio rejected the message: '.($response->json('message') ?? $response->status()));
        }

        return is_string($sid = $response->json('sid')) ? $sid : null;
    }
}
