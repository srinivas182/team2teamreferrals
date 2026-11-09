<?php

declare(strict_types=1);

namespace App\Domains\Identity\Phone;

use App\Domains\Identity\Models\User;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\Cache;

/**
 * Six-digit codes for mobile verification. Codes are stored hashed, expire after
 * 10 minutes and are invalidated after 5 wrong attempts.
 */
final class PhoneVerifier
{
    private const TTL_SECONDS = 600;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsSender $sms) {}

    public function send(User $user): void
    {
        if ($user->phone === null) {
            return;
        }
        $code = (string) random_int(100000, 999999);
        Cache::put($this->key($user), ['hash' => hash('sha256', $code), 'attempts' => 0], self::TTL_SECONDS);
        $this->sms->send($user->phone, "{$code} is your Team to Team verification code. It expires in 10 minutes. Don't share it with anyone.");
    }

    public function verify(User $user, string $code): bool
    {
        /** @var array{hash: string, attempts: int}|null $entry */
        $entry = Cache::get($this->key($user));
        if ($entry === null || $entry['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (! hash_equals($entry['hash'], hash('sha256', trim($code)))) {
            Cache::put($this->key($user), ['hash' => $entry['hash'], 'attempts' => $entry['attempts'] + 1], self::TTL_SECONDS);

            return false;
        }
        Cache::forget($this->key($user));
        $user->forceFill(['phone_verified_at' => now()])->save();

        return true;
    }

    private function key(User $user): string
    {
        return 'phone-otp:'.$user->getKey();
    }
}
