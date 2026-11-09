<?php

use App\Domains\Seo\Events\PublicUrlsChanged;
use App\Domains\Seo\Listeners\NotifySearchEngines;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['seo.canonical_url' => 'https://teamtoteamreferrals.com', 'seo.indexable' => true]);
});

it('server-renders the home page with SEO props', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Home')
            ->where('seo.canonical', 'https://teamtoteamreferrals.com/')
            ->where('seo.robots', 'index, follow, max-image-preview:large, max-snippet:-1')
            ->has('seo.schema', 2));
});

it('appends the site name to inner page titles and trims descriptions to 160 characters', function () {
    $data = (new SeoData('Pricing', str_repeat('a', 300), '/pricing'))->toArray();
    expect($data['title'])->toBe('Pricing | Team to Team Referrals')
        ->and(mb_strlen($data['description']))->toBeLessThanOrEqual(160)
        ->and($data['canonical'])->toBe('https://teamtoteamreferrals.com/pricing');
});

it('marks pages noindex outside production', function () {
    config(['seo.indexable' => false]);
    expect((new SeoData('Pricing', 'x', '/pricing'))->toArray()['robots'])->toBe('noindex, nofollow');
    $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('builds valid FAQ and breadcrumb structured data', function () {
    $faq = Schema::faq([['Q1?', 'A1'], ['Q2?', 'A2']]);
    expect($faq['@type'])->toBe('FAQPage')->and($faq['mainEntity'])->toHaveCount(2)
        ->and($faq['mainEntity'][0]['acceptedAnswer']['text'])->toBe('A1');
    $crumbs = Schema::breadcrumbs([['Real estate', '/real-estate'], ['Texas', '/real-estate/texas']]);
    expect($crumbs['itemListElement'][1]['position'])->toBe(2)
        ->and($crumbs['itemListElement'][1]['item'])->toBe('https://teamtoteamreferrals.com/real-estate/texas');
});

it('serves robots.txt that allows answer engines and points to the sitemap', function () {
    $body = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
    expect($body)->toContain('User-agent: GPTBot')->toContain('User-agent: ClaudeBot')->toContain('Disallow: /admin')
        ->toContain('Sitemap: https://teamtoteamreferrals.com/sitemap.xml');
});

it('blocks all crawling outside production', function () {
    config(['seo.indexable' => false]);
    expect($this->get('/robots.txt')->getContent())->toBe("User-agent: *\nDisallow: /\n");
});

it('serves a sitemap index and a pages sitemap containing the home page', function () {
    $this->get('/sitemap.xml')->assertOk()->assertSee('https://teamtoteamreferrals.com/sitemaps/pages.xml', false);
    $this->get('/sitemaps/pages.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>https://teamtoteamreferrals.com/</loc>', false);
    $this->get('/sitemaps/unknown.xml')->assertNotFound();
});

it('serves llms.txt with the summary and public URLs', function () {
    $this->get('/llms.txt')->assertOk()->assertSee('# Team to Team Referrals')->assertSee('https://teamtoteamreferrals.com/');
});

it('serves the IndexNow key file only for the configured key', function () {
    $key = str_repeat('a1', 16);
    config(['seo.indexnow.key' => $key]);
    $this->get("/{$key}.txt")->assertOk()->assertSee($key);
    $this->get('/'.str_repeat('b2', 16).'.txt')->assertNotFound();
});

it('notifies IndexNow in the background when public URLs change', function () {
    Event::fake([PublicUrlsChanged::class]);
    PublicUrlsChanged::dispatch(['/real-estate/texas/houston']);
    Event::assertListening(PublicUrlsChanged::class, NotifySearchEngines::class);
    Event::assertDispatched(PublicUrlsChanged::class);
});

it('submits changed URLs to IndexNow when enabled', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
    config(['seo.indexnow.enabled' => true, 'seo.indexnow.key' => str_repeat('c3', 16)]);
    app(NotifySearchEngines::class)->handle(new PublicUrlsChanged(['/pricing']));
    Http::assertSent(fn ($request) => $request['host'] === 'teamtoteamreferrals.com'
        && $request['urlList'] === ['https://teamtoteamreferrals.com/pricing']);
});

it('redirects non-canonical hosts and trailing slashes permanently', function () {
    config(['seo.force_canonical' => true]);
    $this->get('http://www.teamtoteamreferrals.com/pricing/?a=1')
        ->assertStatus(301)->assertRedirect('https://teamtoteamreferrals.com/pricing?a=1');
});
