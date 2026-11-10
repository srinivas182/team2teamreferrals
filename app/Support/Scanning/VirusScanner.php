<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/** Scans a file for malware. Returns "clean", "infected", or "unavailable" (no scanner reachable). */
interface VirusScanner
{
    public function scan(string $absolutePath): string;
}
