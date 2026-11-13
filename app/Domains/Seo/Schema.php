<?php

declare(strict_types=1);

namespace App\Domains\Seo;

/**
 * Builders for schema.org JSON-LD, so every page type emits valid structured data
 * that search engines and AI answer engines can read.
 */
final class Schema
{
    /** @return array<string, mixed> */
    public static function organization(): array
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $base.'/#organization',
            'name' => config('seo.organization.name'),
            'url' => $base,
            'logo' => $base.config('seo.organization.logo'),
            'sameAs' => config('seo.organization.same_as') ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function website(): array
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $base.'/#website',
            'name' => config('seo.site_name'),
            'url' => $base,
            'publisher' => ['@id' => $base.'/#organization'],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items  [name, path] pairs, in order
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $i): array => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item[0],
                'item' => $base.'/'.ltrim($item[1], '/'),
            ], $items, array_keys($items)),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $qa  [question, answer] pairs
     * @return array<string, mixed>
     */
    public static function faq(array $qa): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $pair): array => [
                '@type' => 'Question',
                'name' => $pair[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair[1]],
            ], $qa),
        ];
    }

    /** @return array<string, mixed> */
    public static function article(string $headline, string $path, string $author, string $published, ?string $modified = null, ?string $description = null, ?string $reviewer = null, ?string $image = null): array
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $headline,
            'description' => $description,
            'image' => $image,
            'mainEntityOfPage' => $base.'/'.ltrim($path, '/'),
            'author' => ['@type' => 'Person', 'name' => $author],
            // "Reviewed by" (an expert who checked the facts), where there is one.
            'reviewedBy' => $reviewer !== null && $reviewer !== '' ? ['@type' => 'Person', 'name' => $reviewer] : null,
            'publisher' => ['@id' => $base.'/#organization'],
            'datePublished' => $published,
            'dateModified' => $modified ?? $published,
        ], fn ($v) => $v !== null);
    }
}
