<?php

declare(strict_types=1);

namespace App\Domains\Seo;

/**
 * Metadata for one public page. Rendered server-side (Inertia SSR) into <head>:
 * title, description, canonical, robots, Open Graph, Twitter and JSON-LD.
 */
final readonly class SeoData
{
    /**
     * @param  list<array<string, mixed>>  $schema  JSON-LD objects for this page
     */
    public function __construct(
        public string $title,
        public string $description,
        public ?string $path = null,
        public bool $index = true,
        public ?string $image = null,
        public string $type = 'website',
        public array $schema = [],
        public ?string $modifiedAt = null,
    ) {}

    /** Private pages (auth, portal, admin): titled for the browser tab, never indexed. */
    public static function private(string $title): self
    {
        return new self(title: $title, description: (string) config('seo.default_description'), index: false);
    }

    /**
     * @return array{title: string, description: string, canonical: string, robots: string, image: string, type: string, siteName: string, locale: string, schema: list<array<string, mixed>>, modifiedAt: ?string}
     */
    public function toArray(): array
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');
        $siteName = (string) config('seo.site_name');
        $indexable = $this->index && (bool) config('seo.indexable');

        return [
            'title' => $this->path === '/' ? $this->title : $this->title.' | '.$siteName,
            'description' => mb_strimwidth($this->description, 0, 160, '…'),
            'canonical' => $base.($this->path === null ? '' : '/'.ltrim($this->path, '/')),
            'robots' => $indexable ? 'index, follow, max-image-preview:large, max-snippet:-1' : 'noindex, nofollow',
            'image' => $base.($this->image ?? (string) config('seo.default_image')),
            'type' => $this->type,
            'siteName' => $siteName,
            'locale' => (string) config('seo.locale'),
            'schema' => $this->schema,
            'modifiedAt' => $this->modifiedAt,
        ];
    }
}
