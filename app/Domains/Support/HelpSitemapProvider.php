<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;
use Illuminate\Support\Facades\DB;

/** Public help articles, their topics and the help centre itself (members-only articles are never listed). */
final class HelpSitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'help';
    }

    public function urls(): iterable
    {
        $articles = DB::table('help_articles')->where(['status' => 'published', 'audience' => 'public'])->get(['slug', 'category_id', 'updated_at']);
        if ($articles->isEmpty()) {
            return;
        }
        yield new SitemapUrl('/help', substr((string) $articles->max('updated_at'), 0, 10), 'weekly', 0.6);
        foreach (DB::table('help_categories')->whereIn('id', $articles->pluck('category_id')->unique())->get(['slug']) as $c) {
            yield new SitemapUrl('/help/topic/'.$c->slug, now()->toDateString(), 'weekly', 0.5);
        }
        foreach ($articles as $a) {
            yield new SitemapUrl('/help/'.$a->slug, substr((string) $a->updated_at, 0, 10), 'monthly', 0.6);
        }
    }
}
