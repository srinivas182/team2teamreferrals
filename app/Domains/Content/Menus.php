<?php

declare(strict_types=1);

namespace App\Domains\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Header and footer menus for the public site (cached; cleared on save). */
final class Menus
{
    public const LOCATIONS = ['header' => 8, 'footer' => 20];

    /** @return array<string, list<array{label: string, url: string}>> */
    public static function all(): array
    {
        return Cache::rememberForever('cms.menus', fn () => DB::table('menus')->pluck('items', 'location')->map(fn ($j) => array_values((array) json_decode((string) $j, true)))->all());
    }

    /** @param list<array<string, mixed>> $items */
    public static function save(string $location, array $items): void
    {
        abort_unless(isset(self::LOCATIONS[$location]), 404);
        $clean = [];
        foreach (array_slice($items, 0, self::LOCATIONS[$location]) as $i) {
            $label = mb_substr(trim(strip_tags((string) ($i['label'] ?? ''))), 0, 40);
            $url = trim((string) ($i['url'] ?? ''));
            if ($label === '' && $url === '') {
                continue;
            }
            if ($label === '' || ! (str_starts_with($url, '/') || str_starts_with(strtolower($url), 'https://'))) {
                throw ValidationException::withMessages(['items' => 'Each menu link needs a label and an address starting with / or https://.']);
            }
            $clean[] = ['label' => $label, 'url' => mb_substr($url, 0, 300)];
        }
        DB::table('menus')->updateOrInsert(['location' => $location], ['items' => json_encode($clean), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget('cms.menus');
    }
}
