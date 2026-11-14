<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Seo\Schema;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Validation\ValidationException;

/**
 * The page builder's blocks: what each may contain (validated and cleaned) and what it tells search and answer
 * engines. FAQ becomes FAQPage, steps become HowTo, pricing becomes Offers. Testimonials become Reviews only when the
 * quote is linked to a member who agreed to it (otherwise they are shown as quotes, with no review markup).
 */
final class Blocks
{
    public const TYPES = [
        'hero' => 'Hero', 'features' => 'Features', 'steps' => 'Steps', 'stats' => 'Stats', 'testimonials' => 'Testimonials',
        'faq' => 'FAQ', 'pricing' => 'Pricing', 'cta' => 'Call to action', 'rich_text' => 'Rich text', 'media' => 'Image or video', 'contact_form' => 'Contact form',
    ];

    /** Video embeds only from these hosts. */
    public const EMBED_HOSTS = ['www.youtube.com', 'youtube.com', 'youtu.be', 'player.vimeo.com', 'vimeo.com'];

    /**
     * Validate and clean one block; throws with a message naming the block.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public static function clean(array $b, int $n): array
    {
        $type = (string) ($b['type'] ?? '');
        $fail = fn (string $msg) => throw ValidationException::withMessages(["blocks.{$n}" => 'Block '.($n + 1).' ('.(self::TYPES[$type] ?? 'unknown').'): '.$msg]);
        if (! isset(self::TYPES[$type])) {
            $fail('choose a block type.');
        }
        $t = fn (string $k, int $max = 200) => mb_substr(trim(strip_tags((string) ($b[$k] ?? ''))), 0, $max);
        $items = fn (string $k, array $fields, int $max = 12) => array_slice(array_values(array_filter(array_map(
            fn ($i) => array_map(fn ($f) => mb_substr(trim(strip_tags((string) (is_array($i) ? ($i[$f] ?? '') : ''))), 0, 600), array_combine($fields, $fields)),
            is_array($b[$k] ?? null) ? $b[$k] : []), fn ($i) => implode('', $i) !== '')), 0, $max);
        $url = function (string $k) use ($b, $fail): string {
            $u = trim((string) ($b[$k] ?? ''));
            if ($u !== '' && ! str_starts_with($u, '/') && ! preg_match('#^https://#i', $u)) {
                $fail('links must start with / or https://.');
            }

            return mb_substr($u, 0, 300);
        };
        $out = match ($type) {
            'hero' => ['heading' => $t('heading', 120), 'subheading' => $t('subheading', 300), 'cta_label' => $t('cta_label', 40), 'cta_url' => $url('cta_url'), 'image' => $url('image'), 'image_alt' => $t('image_alt', 160)],
            'features', 'steps' => ['heading' => $t('heading', 120), 'items' => $items('items', ['title', 'text'])],
            'stats' => ['items' => $items('items', ['value', 'label'], 6)],
            'testimonials' => ['items' => self::testimonials(is_array($b['items'] ?? null) ? $b['items'] : [], $fail)],
            'contact_form' => ['heading' => $t('heading', 120), 'intro' => $t('intro', 300)],
            'faq' => ['heading' => $t('heading', 120), 'items' => $items('items', ['q', 'a'], 20)],
            'pricing' => ['heading' => $t('heading', 120), 'plans' => array_map(fn ($p) => [...$p, 'features' => array_values(array_filter(array_map('trim', explode("\n", $p['features']))))],
                self::checkLinks($items('plans', ['name', 'price', 'period', 'features', 'cta_label', 'cta_url'], 4), $fail))],
            'cta' => ['heading' => $t('heading', 120), 'text' => $t('text', 300), 'label' => $t('label', 40), 'url' => $url('url')],
            'rich_text' => ['html' => self::purify((string) ($b['html'] ?? ''))],
            'media' => ['image' => $url('image'), 'alt' => $t('alt', 160), 'caption' => $t('caption', 200), 'video' => self::embed((string) ($b['video'] ?? ''), $fail)],
        };
        // Images need alt text (accessibility, and what answer engines read).
        if (($type === 'media' && $out['image'] !== '' && $out['alt'] === '') || ($type === 'hero' && $out['image'] !== '' && $out['image_alt'] === '')) {
            $fail('describe the image (alt text).');
        }
        if ($type === 'media' && $out['image'] === '' && $out['video'] === '') {
            $fail('add an image or a video.');
        }

        return ['type' => $type, ...$out];
    }

    /**
     * Structured data for a page's blocks.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function schema(array $blocks, string $title, string $url): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $out[] = match ($b['type']) {
                'faq' => $b['items'] !== [] ? Schema::faq(array_values(array_map(fn ($i) => [(string) $i['q'], (string) $i['a']], $b['items']))) : null, // [question, answer] pairs
                'steps' => $b['items'] !== [] ? ['@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => $b['heading'] !== '' ? $b['heading'] : $title,
                    'step' => array_map(fn ($i, int $n) => ['@type' => 'HowToStep', 'position' => $n, 'name' => $i['title'], 'text' => $i['text']], $b['items'], range(1, max(1, count($b['items']))))] : null,
                'pricing' => $b['plans'] !== [] ? ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $title, 'url' => $url,
                    'provider' => ['@type' => 'Organization', 'name' => (string) config('seo.organization.name'), 'url' => (string) config('seo.canonical_url')], 'areaServed' => ['@type' => 'Country', 'name' => 'United States'],
                    'offers' => array_map(fn ($p) => ['@type' => 'Offer', 'name' => $p['name'], 'price' => preg_replace('/[^0-9.]/', '', (string) $p['price']), 'priceCurrency' => 'USD', 'url' => $url], $b['plans'])] : null,
                // Review markup only for quotes linked to a member's recorded consent (Sprint 37b); otherwise none.
                'testimonials' => ($genuine = array_values(array_filter($b['items'], fn ($i) => ! empty($i['consent_id'])))) !== []
                    ? ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Team to Team Referrals', 'review' => array_map(fn ($i) => ['@type' => 'Review', 'reviewBody' => $i['quote'], 'author' => ['@type' => 'Person', 'name' => $i['author']]], $genuine)] : null,
                default => null,
            };
        }

        return array_values(array_filter($out));
    }

    /**
     * Testimonial quotes. Linked to a member's approved, consented testimonial, the words come from their record (so
     * they can't be edited into something the member didn't say); typed quotes are shown without review markup.
     *
     * @param  array<mixed>  $raw
     * @return list<array{quote: string, author: string, business: string, consent_id: ?int}>
     */
    private static function testimonials(array $raw, callable $fail): array
    {
        $out = [];
        foreach (array_slice($raw, 0, 12) as $i) {
            $i = is_array($i) ? $i : [];
            $id = isset($i['consent_id']) && is_numeric($i['consent_id']) && (int) $i['consent_id'] > 0 ? (int) $i['consent_id'] : null;
            if ($id !== null) {
                $t = \Illuminate\Support\Facades\DB::table('testimonials')->where(['id' => $id, 'status' => 'approved'])->first();
                if ($t === null) {
                    $fail('that testimonial isn’t approved (or was withdrawn).'); // throws

                    continue;
                }
                $out[] = ['quote' => (string) $t->quote, 'author' => (string) $t->author_name, 'business' => (string) ($t->business ?? ''), 'consent_id' => $id];

                continue;
            }
            $quote = mb_substr(trim(strip_tags((string) ($i['quote'] ?? ''))), 0, 600);
            if ($quote !== '') {
                $out[] = ['quote' => $quote, 'author' => mb_substr(trim(strip_tags((string) ($i['author'] ?? ''))), 0, 120), 'business' => mb_substr(trim(strip_tags((string) ($i['business'] ?? ''))), 0, 160), 'consent_id' => null];
            }
        }

        return $out;
    }

    /**
     * When a page is shown: consented quotes are re-checked, so a withdrawn testimonial disappears at once.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function resolve(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'testimonials') {
                foreach ((array) ($b['items'] ?? []) as $i) {
                    if (! empty($i['consent_id'])) {
                        $ids[] = (int) $i['consent_id'];
                    }
                }
            }
        }
        $live = $ids === [] ? [] : \Illuminate\Support\Facades\DB::table('testimonials')->whereIn('id', $ids)->where('status', 'approved')->pluck('id')->all();

        return array_map(fn ($b) => ($b['type'] ?? '') !== 'testimonials' ? $b : [...$b, 'items' => array_values(array_filter((array) ($b['items'] ?? []),
            fn ($i) => empty($i['consent_id']) || in_array((int) $i['consent_id'], $live, true)))], $blocks);
    }

    /**
     * Plan links must start with / or https:// (checked before features are split into a list).
     *
     * @param  list<array<string, string>>  $plans
     * @return list<array<string, string>>
     */
    private static function checkLinks(array $plans, callable $fail): array
    {
        foreach ($plans as $p) {
            $u = $p['cta_url'] ?? '';
            if ($u !== '' && ! str_starts_with($u, '/') && ! str_starts_with(strtolower($u), 'https://')) {
                $fail('links must start with / or https://.');
            }
        }

        return $plans;
    }

    public static function purify(string $html): string
    {
        $c = HTMLPurifier_Config::createDefault();
        $c->set('Cache.DefinitionImpl', null);
        $c->set('HTML.Allowed', 'p,br,strong,em,b,i,u,h2,h3,h4,ul,ol,li,blockquote,code,pre,hr,a[href|title]');
        $c->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $c->set('HTML.TargetBlank', true);
        $c->set('HTML.Nofollow', false);

        return mb_substr((new HTMLPurifier($c))->purify($html), 0, 30000);
    }

    /** A YouTube or Vimeo link, as its embed address; anything else is refused. */
    private static function embed(string $url, callable $fail): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, self::EMBED_HOSTS, true) || ! str_starts_with(strtolower($url), 'https://')) {
            $fail('videos can come from YouTube or Vimeo only.');
        }
        if (preg_match('#(?:youtu\.be/|v=|/embed/)([A-Za-z0-9_-]{6,20})#', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d{4,12})#', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }
        $fail('that video link isn’t recognised.');

        return '';
    }
}
