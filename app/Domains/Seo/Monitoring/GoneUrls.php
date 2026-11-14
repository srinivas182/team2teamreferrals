<?php

declare(strict_types=1);

namespace App\Domains\Seo\Monitoring;

use App\Domains\Seo\Events\PublicUrlsChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pages removed for good (a profile made private, a post unpublished, a group closed) answer "410 Gone" instead of
 * "404 Not found", so search engines drop them quickly instead of retrying for weeks; search engines are pinged at
 * once. A page that comes back is simply taken off the list.
 */
final class GoneUrls
{
    public static function mark(string $path, string $reason): void
    {
        $path = self::normalise($path);
        DB::table('gone_urls')->upsert([['path' => $path, 'reason' => mb_substr($reason, 0, 40), 'gone_at' => now()]], ['path'], ['reason', 'gone_at']);
        Cache::forget('seo:gone:'.sha1($path));
        PublicUrlsChanged::dispatch([$path]);
    }

    public static function restore(string $path): void
    {
        $path = self::normalise($path);
        if (DB::table('gone_urls')->where('path', $path)->delete() > 0) {
            Cache::forget('seo:gone:'.sha1($path));
        }
    }

    public static function isGone(string $path): bool
    {
        $path = self::normalise($path);

        return (bool) Cache::remember('seo:gone:'.sha1($path), 3600, fn () => DB::table('gone_urls')->where('path', $path)->exists());
    }

    private static function normalise(string $path): string
    {
        return '/'.trim((string) parse_url($path, PHP_URL_PATH), '/');
    }
}
