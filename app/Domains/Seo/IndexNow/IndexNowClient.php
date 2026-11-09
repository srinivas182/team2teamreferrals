<?php

declare(strict_types=1);

namespace App\Domains\Seo\IndexNow;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells IndexNow search engines (Bing, Yandex, Seznam, Naver and others) the moment
 * public URLs change, instead of waiting for the next crawl.
 */
final class IndexNowClient
{
    public function enabled(): bool
    {
        return (bool) config('seo.indexnow.enabled') && (bool) config('seo.indexable') && filled(config('seo.indexnow.key'));
    }

    /** @param list<string> $paths */
    public function submit(array $paths): bool
    {
        if (! $this->enabled() || $paths === []) {
            return false;
        }
        $base = rtrim((string) config('seo.canonical_url'), '/');
        $key = (string) config('seo.indexnow.key');

        $response = Http::timeout(10)->acceptJson()->post((string) config('seo.indexnow.endpoint'), [
            'host' => (string) parse_url($base, PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => $base.'/'.$key.'.txt',
            'urlList' => array_values(array_unique(array_map(fn (string $p): string => $base.'/'.ltrim($p, '/'), $paths))),
        ]);
        if (! $response->successful()) {
            Log::warning('IndexNow submission failed', ['status' => $response->status()]);
        }

        return $response->successful();
    }
}
