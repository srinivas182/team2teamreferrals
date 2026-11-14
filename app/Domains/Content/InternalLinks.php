<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Content\Industry\IndustryContent;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Automatic internal links (Sprint 43): the first mention of a published industry in an article links to its
 * industry page, and articles and industry pages point to each other. Helps readers, search engines and answer
 * engines find the page that answers each topic, without anyone adding links by hand.
 */
final class InternalLinks
{
    /** At most this many automatic links per article, so the text stays readable. */
    public const MAX_LINKS = 5;

    /** @return list<array{name: string, slug: string, url: string}> published industry pages, longest name first */
    public static function targets(): array
    {
        return array_values(Cache::remember('content:link-targets', 600, fn () => IndustryContent::published()
            ->map(fn ($i) => ['name' => (string) $i->name, 'slug' => (string) $i->slug, 'url' => '/industries/'.$i->slug])
            ->sortByDesc(fn ($t) => mb_strlen($t['name']))->values()->all()));
    }

    /**
     * Link the first mention of each industry in an article's HTML. Text already inside a link, a heading or code is
     * left alone. Returns the new HTML and the industries it mentions (linked or not).
     *
     * @return array{html: string, mentioned: list<array{name: string, slug: string, url: string}>}
     */
    public static function apply(string $html): array
    {
        $targets = self::targets();
        if ($targets === [] || trim($html) === '') {
            return ['html' => $html, 'mentioned' => []];
        }
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="t2t-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $mentioned = [];
        $links = 0;
        $found = $x->query('//text()[not(ancestor::a) and not(ancestor::h1) and not(ancestor::h2) and not(ancestor::h3) and not(ancestor::h4) and not(ancestor::code) and not(ancestor::pre)]');
        $nodes = $found === false ? [] : array_values(array_filter(iterator_to_array($found), fn ($n) => $n instanceof DOMText));
        foreach ($targets as $t) {
            $re = '/(?<![\pL\pN-])'.preg_quote($t['name'], '/').'(?![\pL\pN-])/iu';
            foreach ($nodes as $i => $node) {
                if (! preg_match($re, (string) $node->nodeValue, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $mentioned[] = $t;
                if ($links < self::MAX_LINKS) {
                    // Split the text around the match and put a link in the middle.
                    $offset = mb_strlen(substr((string) $node->nodeValue, 0, (int) $m[0][1]));
                    $after = $node->splitText($offset);
                    $rest = $after === false ? false : $after->splitText(mb_strlen($m[0][0]));
                    if ($after === false || $rest === false) {
                        break;
                    }
                    $a = $dom->createElement('a');
                    $a->setAttribute('href', $t['url']);
                    $a->appendChild($dom->createTextNode((string) $after->nodeValue));
                    $after->parentNode?->replaceChild($a, $after);
                    $nodes[$i] = $node;
                    $nodes[] = $rest;
                    $links++;
                }
                break;
            }
        }
        $root = $dom->getElementById('t2t-root');
        $out = '';
        if ($root instanceof DOMElement) {
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
        }

        return ['html' => $out, 'mentioned' => $mentioned];
    }

    /**
     * Published articles that mention an industry (for "Related articles" on its industry page).
     *
     * @return list<array{title: string, url: string}>
     */
    public static function articlesAbout(string $industryName, int $limit = 4): array
    {
        return array_values(DB::table('posts')->where('status', 'published')->where(fn ($q) => $q->where('body', 'like', '%'.$industryName.'%')->orWhere('title', 'like', '%'.$industryName.'%'))
            ->orderByDesc('published_at')->limit($limit)->get(['title', 'slug'])->map(fn ($p) => ['title' => Tokens::fill((string) $p->title), 'url' => '/blog/'.$p->slug])->all());
    }

    /**
     * Other articles to read next: the newest in the same category, then the newest overall.
     *
     * @return list<array{title: string, url: string}>
     */
    public static function moreArticles(int $postId, ?int $categoryId, int $limit = 3): array
    {
        $base = DB::table('posts')->where('status', 'published')->where('id', '!=', $postId);
        $same = $categoryId !== null ? (clone $base)->where('category_id', $categoryId)->orderByDesc('published_at')->limit($limit)->get(['id', 'title', 'slug']) : collect();
        $more = $same->count() < $limit ? (clone $base)->whereNotIn('id', $same->pluck('id'))->orderByDesc('published_at')->limit($limit - $same->count())->get(['id', 'title', 'slug']) : collect();

        return array_values($same->concat($more)->map(fn ($p) => ['title' => Tokens::fill((string) $p->title), 'url' => '/blog/'.$p->slug])->all());
    }
}
