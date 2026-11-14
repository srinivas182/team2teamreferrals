<?php

declare(strict_types=1);

namespace App\Domains\Seo\Audit;

/**
 * Structured-data validation: the properties search engines need for each type we publish (Google's required and
 * recommended fields, kept to what our pages can always supply). Returns plain-English problems, empty when valid.
 */
final class SchemaRules
{
    /** Types that tell answer engines who or what a page is about. */
    public const ENTITY_TYPES = ['Organization', 'LocalBusiness', 'ProfessionalService', 'RealEstateAgent', 'Service', 'Place', 'City', 'State',
        'Person', 'WebSite', 'DefinedTermSet', 'ItemList', 'CollectionPage', 'AboutPage'];

    /** @var array<string, list<string>> required properties by type ("a|b" means either) */
    private const REQUIRED = [
        'Organization' => ['name', 'url'],
        'WebSite' => ['name', 'url'],
        'WebPage' => ['name', 'url'],
        'CollectionPage' => ['name', 'url'],
        'AboutPage' => ['name', 'url'],
        'LocalBusiness' => ['name', 'address|areaServed'],
        'ProfessionalService' => ['name', 'address|areaServed'],
        'RealEstateAgent' => ['name', 'address|areaServed'],
        'Service' => ['name|serviceType', 'provider|areaServed'],
        'FAQPage' => ['mainEntity'],
        'BreadcrumbList' => ['itemListElement'],
        'ItemList' => ['itemListElement'],
        'Article' => ['headline', 'datePublished', 'author'],
        'BlogPosting' => ['headline', 'datePublished', 'author'],
        'Person' => ['name'],
        'Place' => ['name'],
        'DefinedTermSet' => ['name', 'hasDefinedTerm'],
        'AggregateRating' => ['ratingValue', 'ratingCount|reviewCount'],
    ];

    /**
     * Every object in a page's JSON-LD (top level, @graph, and nested objects with an @type), with where it sits.
     *
     * @param  list<mixed>  $blocks  decoded JSON-LD scripts
     * @return list<array<string, mixed>>
     */
    public static function objects(array $blocks): array
    {
        $out = [];
        $walk = function (mixed $node) use (&$walk, &$out): void {
            if (! is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                array_map($walk, $node);

                return;
            }
            if (isset($node['@type'])) {
                $out[] = $node;
            }
            foreach ($node as $k => $v) {
                if ($k !== '@context') {
                    $walk($v);
                }
            }
        };
        array_map($walk, $blocks);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return list<string>
     */
    public static function check(array $object): array
    {
        $types = (array) ($object['@type'] ?? []);
        $problems = [];
        foreach ($types as $type) {
            foreach (self::REQUIRED[$type] ?? [] as $prop) {
                $any = array_filter(explode('|', $prop), fn ($p) => self::filled($object[$p] ?? null));
                if ($any === []) {
                    $problems[] = "{$type} is missing ".str_replace('|', ' or ', $prop);
                }
            }
            $problems = [...$problems, ...match ($type) {
                'FAQPage' => self::faq($object),
                'BreadcrumbList', 'ItemList' => self::list($type, $object),
                'Article', 'BlogPosting' => self::dates($type, $object),
                'AggregateRating' => self::rating($object),
                default => [],
            }];
        }
        foreach (['url', 'item', 'logo', 'image'] as $p) {
            if (is_string($object[$p] ?? null) && $object[$p] !== '' && ! preg_match('#^https?://#', $object[$p])) {
                $problems[] = ($types[0] ?? 'Item')." has a relative {$p} (needs a full https address): {$object[$p]}";
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return list<string>
     */
    private static function faq(array $o): array
    {
        $p = [];
        foreach ((array) ($o['mainEntity'] ?? []) as $i => $q) {
            if (! is_array($q) || ($q['@type'] ?? null) !== 'Question' || ! self::filled($q['name'] ?? null) || ! self::filled($q['acceptedAnswer']['text'] ?? null)) {
                $p[] = 'FAQPage question '.((int) $i + 1).' needs a name and an accepted answer';
            }
        }

        return $p;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return list<string>
     */
    private static function list(string $type, array $o): array
    {
        $items = array_values((array) ($o['itemListElement'] ?? []));
        $p = [];
        foreach ($items as $i => $item) {
            if (! is_array($item) || (int) ($item['position'] ?? 0) !== $i + 1) {
                $p[] = "{$type} item ".($i + 1).' has the wrong position';
            }
            if ($type === 'BreadcrumbList' && (! self::filled($item['name'] ?? null) || ($i < count($items) - 1 && ! self::filled($item['item'] ?? null)))) {
                $p[] = 'BreadcrumbList item '.($i + 1).' needs a name and a link';
            }
        }

        return $p;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return list<string>
     */
    private static function dates(string $type, array $o): array
    {
        $p = [];
        foreach (['datePublished', 'dateModified'] as $d) {
            if (isset($o[$d]) && strtotime((string) $o[$d]) === false) {
                $p[] = "{$type} has an unreadable {$d}";
            }
        }
        if (isset($o['datePublished'], $o['dateModified']) && strtotime((string) $o['dateModified']) < strtotime((string) $o['datePublished'])) {
            $p[] = "{$type} was modified before it was published";
        }

        return $p;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return list<string>
     */
    private static function rating(array $o): array
    {
        $v = (float) ($o['ratingValue'] ?? 0);

        return $v < 1 || $v > (float) ($o['bestRating'] ?? 5) ? ['AggregateRating value is out of range'] : [];
    }

    private static function filled(mixed $v): bool
    {
        return is_array($v) ? $v !== [] : trim((string) $v) !== '';
    }
}
