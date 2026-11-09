<?php

declare(strict_types=1);

namespace App\Domains\Seo\Sitemap;

final class SitemapRegistry
{
    /** @var array<string, SitemapProvider> */
    private array $providers = [];

    public function register(SitemapProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    /** @return array<string, SitemapProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    public function find(string $key): ?SitemapProvider
    {
        return $this->providers[$key] ?? null;
    }
}
