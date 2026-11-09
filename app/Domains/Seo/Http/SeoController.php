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
        foreach ((array) config('seo.ai_crawlers') as $bot => $allowed) {
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

    public function llms(): Response
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');
        $lines = ['# '.config('seo.site_name'), '', '> '.config('seo.llms.summary'), ''];
        foreach ($this->sitemaps->all() as $key => $provider) {
            $lines[] = '## '.ucfirst($key);
            $lines[] = '';
            foreach ($provider->urls() as $url) {
                $lines[] = '- '.$base.'/'.ltrim($url->path, '/');
            }
            $lines[] = '';
        }

        return $this->text(implode("\n", $lines));
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
