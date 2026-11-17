<?php

declare(strict_types=1);

namespace App\Domains\Support\Ally;

/** Removes personal details from text before it is saved or sent to an AI provider: emails, phone and card numbers. */
final class Redact
{
    public static function text(string $s): string
    {
        $s = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $s);
        $s = (string) preg_replace('/\b(?:\d[ \-]?){13,19}\b/', '[card number]', $s);
        $s = (string) preg_replace('/(?<!\w)(?:\+?\d{1,3}[\s.\-]?)?(?:\(\d{3}\)|\d{3})[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\w)/', '[phone]', $s);

        return (string) preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[number]', $s);
    }
}
