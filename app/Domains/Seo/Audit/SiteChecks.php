<?php

declare(strict_types=1);

namespace App\Domains\Seo\Audit;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Checks across pages (Sprint 43b): internal links that lead nowhere, titles and descriptions used by more than one
 * page (search engines treat those pages as the same), and pages whose text is almost identical (thin, templated
 * pages that rarely rank). Fed the pages the audit already fetched, so the site is crawled once.
 */
final class SiteChecks
{
    /** Pages sharing at least this much of their wording are reported as near-duplicates (a warning, not a failure). */
    public const NEAR_DUPLICATE = 0.9;

    /** @var array<string, array{title: string, description: string, links: list<string>, shingles: array<int, true>}> */
    private array $pages = [];

    public function add(string $path, string $html, string $host): void
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $links = [];
        foreach ($x->query('//body//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement || $a->getAttribute('target') === '_blank' && str_contains($a->getAttribute('rel'), 'nofollow')) {
                continue;
            }
            $href = trim($a->getAttribute('href'));
            $h = parse_url($href);
            if ($href === '' || str_starts_with($href, '#') || ! is_array($h) || isset($h['scheme']) && ! in_array($h['scheme'], ['http', 'https'], true)) {
                continue;
            }
            if (isset($h['host']) && strtolower($h['host']) !== strtolower($host)) {
                continue; // other sites aren't checked here
            }
            $p = '/'.ltrim((string) ($h['path'] ?? '/'), '/');
            if (! preg_match('#^/(app|admin|login|register|logout|forgot-password|build|storage|embed|og|map|c|r|q|request)(/|$)#', $p)) {
                $links[$p] = true;
            }
        }
        $first = function (string $q) use ($x): ?DOMNode {
            $found = $x->query($q);

            $node = $found === false ? null : $found->item(0);

            return $node instanceof DOMNode ? $node : null;
        };
        $title = trim((string) $first('//head/title')?->textContent);
        $m = $first('//meta[@name="description"]');
        $desc = $m instanceof DOMElement ? trim($m->getAttribute('content')) : '';
        $main = $first('//main') ?? $first('//body');
        $this->pages[$path] = ['title' => $title, 'description' => $desc, 'links' => array_keys($links), 'shingles' => self::shingles((string) $main?->textContent)];
    }

    /**
     * Internal links not among the pages already checked, each with up to five pages linking to it.
     *
     * @return array<string, list<string>>
     */
    public function linkTargets(): array
    {
        $out = [];
        foreach ($this->pages as $from => $p) {
            foreach ($p['links'] as $to) {
                if (! isset($this->pages[$to]) && count($out[$to] ?? []) < 5) {
                    $out[$to][] = $from;
                }
            }
        }

        return $out;
    }

    /**
     * Links to pages that were themselves checked and failed to load.
     *
     * @param  array<string, int>  $status  path => HTTP status of pages already fetched
     * @return array<string, list<string>>
     */
    public function linksToFailedPages(array $status): array
    {
        $out = [];
        foreach ($this->pages as $from => $p) {
            foreach ($p['links'] as $to) {
                if (($status[$to] ?? 200) >= 400 && count($out[$to] ?? []) < 5) {
                    $out[$to][] = $from;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{titles: array<string, list<string>>, descriptions: array<string, list<string>>, near: list<array{a: string, b: string, similarity: float}>}
     */
    public function duplicates(): array
    {
        $group = function (string $key): array {
            $by = [];
            foreach ($this->pages as $path => $p) {
                $text = $key === 'title' ? $p['title'] : $p['description'];
                if ($text !== '') {
                    $by[mb_strtolower($text)][] = $path;
                }
            }

            return array_filter($by, fn ($paths) => count($paths) > 1);
        };
        $near = [];
        $paths = array_keys($this->pages);
        for ($i = 0; $i < count($paths); $i++) {
            for ($j = $i + 1; $j < count($paths); $j++) {
                $a = $this->pages[$paths[$i]]['shingles'];
                $b = $this->pages[$paths[$j]]['shingles'];
                if (count($a) < 20 || count($b) < 20) {
                    continue;
                }
                $shared = count(array_intersect_key($a, $b));
                $sim = $shared / max(1, count($a) + count($b) - $shared);
                if ($sim >= self::NEAR_DUPLICATE) {
                    $near[] = ['a' => $paths[$i], 'b' => $paths[$j], 'similarity' => round($sim, 2)];
                }
            }
        }

        return ['titles' => $group('title'), 'descriptions' => $group('description'), 'near' => $near];
    }

    /** @return array<int, true> five-word runs of the text, hashed */
    private static function shingles(string $text): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim((string) preg_replace('/[^\pL\pN\s]+/u', ' ', $text))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        for ($i = 0; $i + 5 <= count($words); $i++) {
            $out[crc32(implode(' ', array_slice($words, $i, 5)))] = true;
        }

        return $out;
    }
}
