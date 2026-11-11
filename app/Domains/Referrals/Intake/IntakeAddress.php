<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Intake;

use App\Domains\Identity\Models\User;

/** Each member's private address for forwarding leads: intake+{user}.{signature}@{inbound domain}. Unguessable. */
final class IntakeAddress
{
    public static function for(User $user): ?string
    {
        $domain = (string) config('mail.inbound.domain');

        return $domain === '' ? null : 'intake+'.$user->id.'.'.self::sign($user->id).'@'.$domain;
    }

    /** The member this address belongs to, or null when the address isn't a valid intake address. */
    public static function user(string $to): ?User
    {
        if (! preg_match('/intake\+(\d+)\.([a-f0-9]{16})@/i', strtolower($to), $m) || ! hash_equals(self::sign((int) $m[1]), $m[2])) {
            return null;
        }

        return User::query()->find((int) $m[1]);
    }

    public static function isIntake(string $to): bool
    {
        return str_contains(strtolower($to), 'intake+');
    }

    private static function sign(int $userId): string
    {
        return substr(hash_hmac('sha256', 'intake:'.$userId, (string) config('app.key')), 0, 16);
    }
}
