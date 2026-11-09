<?php

declare(strict_types=1);

namespace App\Domains\Identity\Phone;

/** Normalises US mobile numbers to E.164 (+1XXXXXXXXXX). Other countries arrive with international expansion. */
final class PhoneNumber
{
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        // NANP: area code and exchange cannot start with 0 or 1.
        if (strlen($digits) !== 10 || ! preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits)) {
            return null;
        }

        return '+1'.$digits;
    }

    public static function display(string $e164): string
    {
        $d = substr($e164, -10);

        return sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6));
    }

    public static function masked(string $e164): string
    {
        return '(•••) •••-'.substr($e164, -4);
    }
}
