<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Spam protection for public forms: a hidden field people never fill ("website_url"), and an encrypted start time
 * (posts under 3 seconds are bots; forms open over 2 hours have gone stale). Used with a route rate limit.
 */
final class FormGuard
{
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 7200;

    public static function token(): string
    {
        return encrypt(now()->timestamp);
    }

    public static function check(Request $request, string $errorField): void
    {
        $started = rescue(fn () => decrypt((string) $request->input('started')), 0, false);
        $elapsed = (int) now()->timestamp - (is_numeric($started) ? (int) $started : 0);
        if (filled($request->input('website_url')) || $elapsed < self::MIN_SECONDS || $elapsed > self::MAX_SECONDS) {
            throw ValidationException::withMessages([$errorField => 'Please try sending that again.']);
        }
    }

    public static function ipHash(Request $request): string
    {
        return hash('sha256', (string) $request->ip().'|'.config('app.key'));
    }
}
