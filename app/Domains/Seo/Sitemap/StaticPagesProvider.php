<?php

declare(strict_types=1);

namespace App\Domains\Seo\Sitemap;

use Illuminate\Support\Facades\Route;

/** Marketing pages. Only routes that exist are listed, so pages appear as they are built. */
final class StaticPagesProvider implements SitemapProvider
{
    /** @var array<string, array{0: string, 1: float}> route name => [change frequency, priority] */
    private const PAGES = [
        'home' => ['daily', 1.0],
        'how-it-works' => ['monthly', 0.8],
        'pricing' => ['monthly', 0.8],
        'groups.index' => ['daily', 0.9],
        'blog.index' => ['daily', 0.7],
        'find-a-pro' => ['weekly', 0.8],
        'about' => ['yearly', 0.5],
    ];

    public function key(): string
    {
        return 'pages';
    }

    public function urls(): iterable
    {
        foreach (self::PAGES as $name => [$frequency, $priority]) {
            if (Route::has($name)) {
                yield new SitemapUrl(route($name, absolute: false), null, $frequency, $priority);
            }
        }
    }
}
