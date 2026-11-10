<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;

/**
 * Per-referral, per-person reply addresses such as reply+3f.9a.c41b0e27d8a1@reply.teamtoteamreferrals.com.
 * Signed with the app key, so they can't be guessed or forged; short enough for every mail system (<64 chars).
 */
final class ReplyAddress
{
    public static function enabled(): bool
    {
        return filled(config('mail.inbound.domain'));
    }

    public static function for(Referral $ref, User $user): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $payload = base_convert((string) $ref->id, 10, 36).'.'.base_convert((string) $user->id, 10, 36);

        return 'reply+'.$payload.'.'.self::sign($payload).'@'.config('mail.inbound.domain');
    }

    /** @return array{referral: int, user: int}|null */
    public static function parse(string $address): ?array
    {
        if (! preg_match('/reply\+([0-9a-z]+)\.([0-9a-z]+)\.([0-9a-f]{12})@/i', strtolower($address), $m)) {
            return null;
        }
        $payload = $m[1].'.'.$m[2];
        if (! hash_equals(self::sign($payload), $m[3])) {
            return null;
        }

        return ['referral' => (int) base_convert($m[1], 36, 10), 'user' => (int) base_convert($m[2], 36, 10)];
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'reply-to|'.$payload, (string) config('app.key')), 0, 12);
    }
}
