<?php

use App\Domains\Seo\Sitemap\SitemapProvider;
use App\Domains\Seo\Sitemap\SitemapRegistry;
use App\Domains\Seo\Sitemap\SitemapUrl;

it('registers providers by key and finds them', function () {
    $provider = new class implements SitemapProvider
    {
        public function key(): string
        {
            return 'cities';
        }

        public function urls(): iterable
        {
            yield new SitemapUrl('/real-estate/texas/houston', '2026-11-08', 'daily', 0.8);
        }
    };

    $registry = new SitemapRegistry;
    $registry->register($provider);

    expect($registry->find('cities'))->toBe($provider)
        ->and($registry->find('missing'))->toBeNull()
        ->and(array_keys($registry->all()))->toBe(['cities']);
});

it('replaces a provider registered twice under the same key', function () {
    $make = fn (string $path) => new class($path) implements SitemapProvider
    {
        public function __construct(private string $path) {}

        public function key(): string
        {
            return 'pages';
        }

        public function urls(): iterable
        {
            yield new SitemapUrl($this->path);
        }
    };

    $registry = new SitemapRegistry;
    $registry->register($make('/a'));
    $registry->register($second = $make('/b'));

    expect($registry->all())->toHaveCount(1)->and($registry->find('pages'))->toBe($second);
});
