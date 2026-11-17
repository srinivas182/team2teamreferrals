<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;

/** The press kit page (Sprint 52b). */
final class PressSitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'press';
    }

    public function urls(): iterable
    {
        $vtt = public_path('press-kit/tour.vtt');

        yield new SitemapUrl('/press', date('Y-m-d', is_file($vtt) ? (int) filemtime($vtt) : time()), 'monthly', 0.4);
    }
}
