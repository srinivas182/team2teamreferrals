<?php

declare(strict_types=1);

namespace App\Domains\Seo\Listeners;

use App\Domains\Seo\Events\PublicUrlsChanged;
use App\Domains\Seo\IndexNow\IndexNowClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

/** Refreshes sitemaps and pings search engines in the background. */
final class NotifySearchEngines implements ShouldQueue
{
    public function __construct(private readonly IndexNowClient $indexNow) {}

    public function handle(PublicUrlsChanged $event): void
    {
        Cache::forget('seo:sitemap:index');
        foreach (array_keys(app(\App\Domains\Seo\Sitemap\SitemapRegistry::class)->all()) as $key) {
            Cache::forget('seo:sitemap:'.$key);
        }
        $this->indexNow->submit($event->paths);
    }
}
