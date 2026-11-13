<?php

declare(strict_types=1);

namespace App\Domains\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Header and footer menus for the public site (cached; cleared on save). */
final class Menus
{
    public const LOCATIONS = ['header' => 8, 'footer' => 20];

    /** @return array<string, list<array{label: string, url: string}>> */
    public static function all(): array
    {
        return Cache::rememberForever('cms.menus', fn () => DB::table('menus')->pluck('items', 'location')->map(fn ($j) => array_values((array) json_decode((string) $j, true)))->all());
    }

    /** Built-in links, used until menus are set in Admin → Website. */
    public const DEFAULT_HEADER = [['How it works', '/how-it-works'], ['Groups', '/groups'], ['Find a pro', '/find-a-pro'], ['Pricing', '/pricing'], ['Blog', '/blog']];

    public const DEFAULT_FOOTER = [
        ['Product', [['How it works', '/how-it-works'], ['Pricing', '/pricing'], ['Group directory', '/groups'], ['Find a pro', '/find-a-pro']]],
        ['Company', [['About', '/about'], ['Blog', '/blog'], ['Contact', '/contact'], ['Status', '/status']]],
        ['Legal', [['Terms', '/terms'], ['Privacy', '/privacy']]],
    ];

    /**
     * Menus for public pages: the admin's, or the built-in ones, keeping only links that open (an app page, a
     * published website page, a redirect, or an outside https link), so the site never links to "not found".
     *
     * @return array{header: list<array{0: string, 1: string}>, footer: list<array{0: string, 1: list<array{0: string, 1: string}>}>}
     */
    public static function forPublic(): array
    {
        return Cache::rememberForever('cms.public-menus', function () {
            $set = self::all();
            $header = isset($set['header']) && $set['header'] !== [] ? array_map(fn ($i) => [$i['label'], $i['url']], $set['header']) : self::DEFAULT_HEADER;
            $footer = isset($set['footer']) && $set['footer'] !== [] ? [['Site', array_map(fn ($i) => [$i['label'], $i['url']], $set['footer'])]] : self::DEFAULT_FOOTER;
            // Every open address worked out at once: published pages and redirects (two queries); app pages in memory.
            $pages = DB::table('cms_pages')->where('status', 'published')->pluck('slug')->map(fn ($s) => '/'.$s)->flip()->all();
            $redirects = DB::table('redirects')->pluck('from_path')->flip()->all();
            $keep = fn (array $links) => array_values(array_filter($links, fn ($l) => self::opens((string) $l[1], $pages, $redirects)));

            return ['header' => $keep($header), 'footer' => array_values(array_filter(array_map(fn ($g) => [$g[0], $keep($g[1])], $footer), fn ($g) => $g[1] !== []))];
        });
    }

    /** Clear the cached public menus (whenever pages, redirects or menus change). */
    public static function forget(): void
    {
        Cache::forget('cms.public-menus');
    }

    /** Does this link open? Outside https links are trusted; our own addresses must reach a real page. */
    /**
     * @param  array<string, int>|null  $pages  published page addresses (looked up when not given)
     * @param  array<string, int>|null  $redirects  redirect sources (looked up when not given)
     */
    public static function opens(string $url, ?array $pages = null, ?array $redirects = null): bool
    {
        if (str_starts_with(strtolower($url), 'https://')) {
            return true;
        }
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
        try {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($path, 'GET'));
            if ($route->getName() !== 'content.page') {
                return true; // an app page
            }
        } catch (Throwable) {
            // no app route: a website page or redirect may still answer
        }

        if ($pages !== null && $redirects !== null) {
            return isset($pages[$path]) || isset($redirects[$path]);
        }

        return DB::table('cms_pages')->where(['slug' => trim($path, '/'), 'status' => 'published'])->exists() || DB::table('redirects')->where('from_path', $path)->exists();
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
        self::forget();
    }
}
