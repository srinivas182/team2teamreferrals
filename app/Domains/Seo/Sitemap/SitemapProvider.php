<?php

declare(strict_types=1);

namespace App\Domains\Seo\Sitemap;

/**
 * Each module that owns public pages (groups, cities, profiles, blog, partners…)
 * registers a provider, so new pages appear in the sitemap automatically.
 */
interface SitemapProvider
{
    /** Short slug used in the sitemap file name, e.g. "pages" → /sitemaps/pages.xml */
    public function key(): string;

    /** @return iterable<SitemapUrl> */
    public function urls(): iterable;
}
