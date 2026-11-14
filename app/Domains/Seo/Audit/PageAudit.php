<?php

declare(strict_types=1);

namespace App\Domains\Seo\Audit;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The SEO and answer-engine checks for one public page, run on the HTML that crawlers actually receive (server
 * rendered). Used by `t2t:seo:audit`, which fails the build in CI, and by the super admin's health report.
 */
final class PageAudit
{
    /** Words the opening must give before the first section heading: who, what, where, cost and how. */
    public const ANSWER_MIN_WORDS = 20;

    /**
     * @param  string  $url  the address the page was fetched from (its canonical must match)
     * @param  string  $llms  llms.txt and llms-full.txt together, to check the page is listed for answer engines
     * @return list<string> problems, empty when the page passes
     */
    public static function check(string $html, string $url, string $llms): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $p = [];
        $meta = fn (string $q): string => self::attr(self::first($x, $q), 'content');

        // Search basics.
        $title = trim((string) self::first($x, '//head/title')?->textContent);
        if ($title === '') {
            $p[] = 'No title';
        } elseif (mb_strlen($title) > 70) {
            $p[] = 'Title is longer than 70 characters ('.mb_strlen($title).')';
        }
        $description = $meta('//meta[@name="description"]');
        if (mb_strlen($description) < 50) {
            $p[] = 'Meta description is missing or under 50 characters';
        }
        $canonical = self::attr(self::first($x, '//link[@rel="canonical"]'), 'href');
        if (! preg_match('#^https?://#', $canonical)) {
            $p[] = 'No canonical link';
        } elseif (rtrim((string) parse_url($canonical, PHP_URL_PATH), '/') !== rtrim((string) parse_url($url, PHP_URL_PATH), '/')) {
            $p[] = "Canonical points elsewhere ({$canonical})";
        }
        if (! str_contains($meta('//meta[@name="robots"]'), 'index') || str_contains($meta('//meta[@name="robots"]'), 'noindex')) {
            $p[] = 'Listed in the sitemap but marked noindex';
        }
        $h1 = count(self::all($x, '//body//h1'));
        if ($h1 !== 1) {
            $p[] = "Has {$h1} main headings (h1); needs exactly one";
        }

        // Structured data.
        $blocks = [];
        foreach (self::all($x, '//script[@type="application/ld+json"]') as $s) {
            $json = json_decode((string) $s->textContent, true);
            if (! is_array($json)) {
                $p[] = 'Structured data that is not valid JSON';

                continue;
            }
            $blocks[] = $json;
        }
        $objects = SchemaRules::objects($blocks);
        if ($objects === []) {
            $p[] = 'No structured data';
        }
        foreach ($objects as $o) {
            $p = [...$p, ...SchemaRules::check($o)];
        }
        $types = array_merge(...array_map(fn ($o) => (array) $o['@type'], $objects ?: [['@type' => []]]));
        if (array_intersect($types, SchemaRules::ENTITY_TYPES) === []) {
            $p[] = 'No entity facts (Organization, LocalBusiness, Service, Place or similar) in the structured data';
        }

        // Answer first: the opening, before the first section heading, says enough to be quoted.
        $words = self::openingWords($x);
        if ($words < self::ANSWER_MIN_WORDS) {
            $p[] = "Opening answer is too short ({$words} words before the first section; needs ".self::ANSWER_MIN_WORDS.')';
        }

        // FAQ: every question in the structured data is visible on the page.
        // Compared without spaces: server-rendered text is split into pieces ("in <!-- -->Austin<!-- -->, TX").
        $visible = self::squash(self::visibleText($x));
        foreach ($objects as $o) {
            if (in_array('FAQPage', (array) $o['@type'], true)) {
                foreach ((array) ($o['mainEntity'] ?? []) as $q) {
                    $name = is_array($q) ? self::squash((string) ($q['name'] ?? '')) : '';
                    if ($name !== '' && ! str_contains($visible, $name)) {
                        $p[] = 'FAQ question in the structured data but not on the page: '.$q['name'];
                    }
                }
            }
        }

        // Dated facts: a modified date in the structured data is shown on the page too.
        foreach ($objects as $o) {
            if (isset($o['dateModified']) && ($d = strtotime((string) $o['dateModified'])) !== false) {
                $day = date('Y-m-d', $d);
                if (self::all($x, '//body//time[starts-with(@datetime, "'.$day.'")]') === []) {
                    $p[] = "Updated date ({$day}) is in the structured data but not shown on the page";
                }
                break;
            }
        }

        // Listed for answer engines.
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (! preg_match('#'.preg_quote($path === '' ? '/' : $path, '#').'([)\s/]|$)#m', $llms) && $path !== '') {
            $p[] = 'Not listed in llms.txt or llms-full.txt';
        }

        return array_values(array_unique($p));
    }

    /** Words in the paragraphs after the main heading and before the first section heading. */
    private static function openingWords(DOMXPath $x): int
    {
        $h1 = self::first($x, '//body//h1');
        if (! $h1 instanceof DOMElement) {
            return 0;
        }
        $words = 0;
        foreach (self::all($x, 'following::*[self::p or self::h2]', $h1) as $el) {
            if ($el->nodeName === 'h2') {
                break;
            }
            $words += str_word_count((string) preg_replace('/[^\pL\pN\s\'’-]/u', ' ', $el->textContent));
        }

        return $words;
    }

    private static function visibleText(DOMXPath $x): string
    {
        $text = '';
        foreach (self::all($x, '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::template)]') as $t) {
            $text .= ' '.$t->nodeValue;
        }

        return $text;
    }

    /** @return list<DOMNode> */
    private static function all(DOMXPath $x, string $expr, ?DOMNode $context = null): array
    {
        $found = $x->query($expr, $context);

        return $found === false ? [] : array_values(array_filter(iterator_to_array($found), fn ($n) => $n instanceof DOMNode));
    }

    private static function first(DOMXPath $x, string $expr): ?DOMNode
    {
        return self::all($x, $expr)[0] ?? null;
    }

    private static function attr(?DOMNode $node, string $name): string
    {
        return $node instanceof DOMElement ? trim($node->getAttribute($name)) : '';
    }

    private static function squash(string $s): string
    {
        return (string) preg_replace('/\s+/u', '', self::norm($s));
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '“', '”', "\u{a0}"], ["'", "'", '"', '"', ' '], html_entity_decode($s)))));
    }
}
