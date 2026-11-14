<?php

declare(strict_types=1);

namespace App\Domains\Seo\Http;

use App\Domains\Seo\Sitemap\SitemapRegistry;
use App\Domains\Seo\Sitemap\SitemapUrl;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/** robots.txt, sitemaps, llms.txt and the IndexNow key file — all generated, never hand-edited. */
final class SeoController
{
    public function __construct(private readonly SitemapRegistry $sitemaps) {}

    public function robots(): Response
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');
        if (! config('seo.indexable')) {
            return $this->text("User-agent: *\nDisallow: /\n");
        }
        $lines = ['User-agent: *'];
        foreach ((array) config('seo.disallow') as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = 'Allow: /';
        foreach (SeoAdminController::allowed(app(\App\Domains\Platform\Settings\Settings::class)) as $bot => $allowed) {
            $lines[] = '';
            $lines[] = 'User-agent: '.$bot;
            foreach ((array) config('seo.disallow') as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = $allowed ? 'Allow: /' : 'Disallow: /';
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$base.'/sitemap.xml';

        return $this->text(implode("\n", $lines)."\n");
    }

    public function sitemapIndex(): Response
    {
        $xml = Cache::remember('seo:sitemap:index', (int) config('seo.sitemap.cache_seconds'), function (): string {
            $base = rtrim((string) config('seo.canonical_url'), '/');
            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach (array_keys($this->sitemaps->all()) as $key) {
                $out .= '  <sitemap><loc>'.e($base.'/sitemaps/'.$key.'.xml').'</loc><lastmod>'.now()->toAtomString().'</lastmod></sitemap>'."\n";
            }

            return $out.'</sitemapindex>'."\n";
        });

        return $this->xml($xml);
    }

    public function sitemap(string $key): Response
    {
        $provider = $this->sitemaps->find($key);
        abort_if($provider === null, 404);

        $xml = Cache::remember('seo:sitemap:'.$key, (int) config('seo.sitemap.cache_seconds'), function () use ($provider): string {
            $base = rtrim((string) config('seo.canonical_url'), '/');
            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            $count = 0;
            /** @var SitemapUrl $url */
            foreach ($provider->urls() as $url) {
                if (++$count > (int) config('seo.sitemap.max_urls_per_file')) {
                    break;
                }
                $out .= '  <url><loc>'.e($base.'/'.ltrim($url->path, '/')).'</loc>'
                    .($url->lastModified ? '<lastmod>'.e($url->lastModified).'</lastmod>' : '')
                    .($url->changeFrequency ? '<changefreq>'.e($url->changeFrequency).'</changefreq>' : '')
                    .($url->priority !== null ? '<priority>'.number_format($url->priority, 1).'</priority>' : '')
                    .'</url>'."\n";
            }

            return $out.'</urlset>'."\n";
        });

        return $this->xml($xml);
    }

    /** Lines listed per sitemap section in llms.txt; llms-full.txt lists every page. */
    public const LLMS_SECTION_LIMIT = 200;

    public function llms(): Response
    {
        return $this->text(Cache::remember('seo:llms', (int) config('seo.sitemap.cache_seconds'), fn () => $this->llmsText(false)));
    }

    /** The full content index: everything in llms.txt plus every public page. Rebuilt whenever public pages change. */
    public function llmsFull(): Response
    {
        return $this->text(Cache::remember('seo:llms-full', (int) config('seo.sitemap.cache_seconds'), fn () => $this->llmsText(true)));
    }

    private function llmsText(bool $full): string
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');
        $lines = ['# '.config('seo.site_name'), '', '> '.config('seo.llms.summary'), '', '## Key facts', ''];
        foreach (\App\Domains\Seo\Aeo::facts() as $fact) {
            $lines[] = '- '.$fact;
        }
        $lines[] = '';
        $lines[] = '## Answers';
        $lines[] = '';
        foreach (\App\Domains\Seo\Aeo::faq() as $qa) {
            $lines[] = '### '.$qa['q'];
            $lines[] = '';
            $lines[] = $qa['a'];
            $lines[] = '';
        }
        // Website pages and blog posts with the answer each one gives (what answer engines quote).
        $summaries = array_filter(\App\Domains\Content\ContentSitemapProvider::entries(), fn ($e) => $e['summary'] !== '');
        if ($summaries !== []) {
            $lines[] = '## Pages and articles';
            $lines[] = '';
            foreach ($summaries as $e) {
                $lines[] = '- ['.$e['title'].']('.$base.$e['path'].'): '.$e['summary'];
            }
            $lines[] = '';
        }
        // Industry facts sheets: quotable statements, each industry named the same way everywhere.
        $hubs = \App\Domains\Content\Industry\IndustryContent::published();
        if ($hubs->isNotEmpty()) {
            $lines[] = '## Industry facts';
            $lines[] = '';
            foreach ($hubs as $i) {
                $lines[] = '### '.$i->name.' ('.$base.'/industries/'.$i->slug.')';
                $lines[] = '';
                foreach (\App\Domains\Content\Industry\IndustryContent::facts($i) as $fact) {
                    $lines[] = '- '.$fact;
                }
                $lines[] = '';
            }
        }
        foreach ($this->sitemaps->all() as $key => $provider) {
            $lines[] = '## '.ucfirst($key);
            $lines[] = '';
            $n = 0;
            foreach ($provider->urls() as $url) {
                if (! $full && ++$n > self::LLMS_SECTION_LIMIT) {
                    $lines[] = '- More pages in this section: '.$base.'/llms-full.txt';
                    break;
                }
                $lines[] = '- '.$base.'/'.ltrim($url->path, '/');
            }
            $lines[] = '';
        }
        if (! $full) {
            $lines[] = '## Full index';
            $lines[] = '';
            $lines[] = '- Every public page: '.$base.'/llms-full.txt';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function indexNowKey(string $key): Response
    {
        abort_unless(filled(config('seo.indexnow.key')) && hash_equals((string) config('seo.indexnow.key'), $key), 404);

        return $this->text($key);
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
