<?php

declare(strict_types=1);

namespace App\Domains\Network\Locations;

use Illuminate\Support\Str;

/** One way to compare place names: "St. George", "Saint George" and "st george" all match. */
final class PlaceName
{
    private const EXPAND = ['st' => 'saint', 'ste' => 'sainte', 'ft' => 'fort', 'mt' => 'mount', 'pt' => 'point'];

    public static function normalize(string $name): string
    {
        $words = preg_split('/\s+/', trim(Str::lower(Str::ascii(preg_replace('/[^\pL\pN\s]+/u', ' ', $name) ?? '')))) ?: [];
        $words = array_map(fn (string $w) => self::EXPAND[$w] ?? $w, array_filter($words, fn ($w) => $w !== ''));

        return implode(' ', $words);
    }
}
