<?php

declare(strict_types=1);

namespace App\Domains\Seo\Sitemap;

final readonly class SitemapUrl
{
    public function __construct(
        public string $path,
        public ?string $lastModified = null,
        public ?string $changeFrequency = null,
        public ?float $priority = null,
    ) {}
}
