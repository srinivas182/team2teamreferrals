<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/** Used when no ClamAV host is configured: nothing is scanned, so downloads wait unless scanning isn't required. */
final class NoScanner implements VirusScanner
{
    public function scan(string $absolutePath): string
    {
        return 'unavailable';
    }
}
