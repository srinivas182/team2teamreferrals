<?php

declare(strict_types=1);

namespace App\Domains\Platform\Payments;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The Stripe connection. Billing itself (subscriptions, trials, credits) arrives in Sprint 22. */
final class Stripe
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly Settings $settings) {}

    public function connected(): bool
    {
        return $this->settings->get('payments.driver') === 'stripe' && $this->settings->has('payments.stripe.secret_key');
    }

    public function mode(): string
    {
        return str_starts_with((string) $this->settings->get('payments.stripe.secret_key'), 'sk_live') || str_starts_with((string) $this->settings->get('payments.stripe.secret_key'), 'rk_live') ? 'live' : 'test';
    }

    /** @return array{name: string, mode: string} */
    public function account(): array
    {
        $res = Http::withToken((string) $this->settings->get('payments.stripe.secret_key'))->timeout(10)->get('https://api.stripe.com/v1/account');
        if ($res->failed()) {
            throw new RuntimeException('Stripe refused the key: '.($res->json('error.message') ?? $res->status()));
        }
        $name = $res->json('settings.dashboard.display_name') ?? $res->json('business_profile.name') ?? $res->json('id');

        return ['name' => (string) $name, 'mode' => $this->mode()];
    }

    /** https://docs.stripe.com/webhooks#verify-manually: HMAC-SHA256 of "{timestamp}.{payload}", within 5 minutes. */
    public static function validSignature(string $payload, string $header, string $secret, ?int $now = null): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int) ($parts['t'][0] ?? 0);
        if ($t === 0 || abs(($now ?? time()) - $t) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($parts['v1'] ?? [] as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
