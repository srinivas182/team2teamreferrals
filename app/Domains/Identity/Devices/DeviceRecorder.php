<?php

declare(strict_types=1);

namespace App\Domains\Identity\Devices;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Recognises the browser a user signs in from via a long-lived, encrypted cookie.
 * Powers the device list, "sign out other devices", new-device alerts and 2FA "remember this device".
 */
final class DeviceRecorder
{
    public const COOKIE = 't2t_device';

    private const TRUST_DAYS = 30;

    public function find(User $user, Request $request): ?UserDevice
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || $token === '') {
            return null;
        }

        return $user->devices()->where('token_hash', hash('sha256', $token))->first();
    }

    /** @return array{device: UserDevice, isNew: bool} */
    public function record(User $user, Request $request): array
    {
        $device = $this->find($user, $request);
        $isNew = $device === null;
        if ($device === null) {
            $token = Str::random(48);
            Cookie::queue(Cookie::make(self::COOKIE, $token, 60 * 24 * 400, httpOnly: true, sameSite: 'lax'));
            $device = $user->devices()->create([
                'token_hash' => hash('sha256', $token),
                'label' => self::label((string) $request->userAgent()),
                'ip_address' => $request->ip(),
                'last_seen_at' => now(),
            ]);
        } else {
            $device->forceFill(['ip_address' => $request->ip(), 'last_seen_at' => now()])->save();
        }

        return ['device' => $device, 'isNew' => $isNew];
    }

    public function trustForTwoFactor(UserDevice $device): void
    {
        $device->forceFill(['two_factor_trusted_until' => now()->addDays(self::TRUST_DAYS)])->save();
    }

    /** "Chrome on macOS" from a user agent string, without a heavy parser. */
    public static function label(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$os}";
    }
}
