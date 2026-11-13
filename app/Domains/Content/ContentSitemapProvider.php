<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapUrl;
use Illuminate\Support\Facades\DB;

/** Published website pages and blog posts (unless set to stay out of search), for the sitemap and llms.txt. */
final class ContentSitemapProvider implements SitemapProvider
{
    public function key(): string
    {
        return 'content';
    }

    public function urls(): iterable
    {
        foreach (self::entries() as $e) {
            yield new SitemapUrl($e['path'], $e['modified'], 'weekly', 0.6);
        }
    }

    /** @return list<array{path: string, title: string, summary: string, modified: string}> */
    public static function entries(): array
    {
        $out = [];
        foreach (DB::table('cms_pages as p')->join('cms_page_versions as v', 'v.id', '=', 'p.published_version_id')->where('p.status', 'published')->orderBy('p.slug')->get(['p.slug', 'p.published_at', 'v.title', 'v.answer_summary', 'v.seo']) as $p) {
            if (! (bool) (json_decode((string) $p->seo, true)['noindex'] ?? false)) {
                $out[] = ['path' => '/'.$p->slug, 'title' => Tokens::fill((string) $p->title), 'summary' => Tokens::fill((string) $p->answer_summary), 'modified' => (string) $p->published_at];
            }
        }
        if (DB::table('posts')->where('status', 'published')->exists()) {
            $out[] = ['path' => '/blog', 'title' => 'Blog', 'summary' => '', 'modified' => (string) DB::table('posts')->where('status', 'published')->max('updated_at')];
        }
        foreach (DB::table('posts')->where('status', 'published')->orderByDesc('published_at')->get(['slug', 'title', 'answer_summary', 'updated_at', 'seo']) as $p) {
            if (! (bool) (json_decode((string) $p->seo, true)['noindex'] ?? false)) {
                $out[] = ['path' => '/blog/'.$p->slug, 'title' => Tokens::fill((string) $p->title), 'summary' => Tokens::fill((string) $p->answer_summary), 'modified' => (string) $p->updated_at];
            }
        }

        return $out;
    }
}
