<?php

declare(strict_types=1);

namespace App\Domains\Platform\Deliveries;

use App\Support\Sms\SmsSender;
use Throwable;

/** Wraps the active SMS driver so every text lands in the delivery log, sent or failed. */
final class RecordingSmsSender implements SmsSender
{
    public function __construct(private readonly SmsSender $inner, private readonly string $provider) {}

    public function send(string $toE164, string $message): ?string
    {
        try {
            $id = $this->inner->send($toE164, $message);
            MessageDelivery::create(['channel' => 'sms', 'provider' => $this->provider, 'recipient' => $toE164, 'subject' => self::type($message), 'status' => 'sent', 'provider_message_id' => $id]);

            return $id;
        } catch (Throwable $e) {
            MessageDelivery::create(['channel' => 'sms', 'provider' => $this->provider, 'recipient' => $toE164, 'subject' => self::type($message), 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
    }

    /** A short label for the log without storing codes or message bodies. */
    private static function type(string $message): string
    {
        return str_contains($message, 'verification code') ? 'Verification code' : mb_substr(preg_replace('/\d{4,}/', '••••', $message) ?? '', 0, 60);
    }
}
