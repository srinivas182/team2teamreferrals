<?php

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Content\InternalLinks;
use App\Domains\Content\Posts;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Profiles\PublicProfiles;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Seo\Events\PublicUrlsChanged;
use App\Domains\Seo\PageImage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:locations:import-metros', ['file' => base_path('tests/fixtures/locations/cbsa.csv')]);
    Cache::flush();
    $this->staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->staff->assignRole(Permissions::SUPER_ADMIN);
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->team = function (string $name, string $city, bool $public = true) {
        $sv = app(Seats::class);
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $sv->approve($sv->apply($this->g, $t, City::where('slug', $city)->firstOrFail(), 'L', null), null);
        PublicProfiles::ensureSlug($t);
        $t->forceFill(['profile_public' => $public])->save();

        return $t->fresh();
    };
    $this->robots = fn ($r) => $r->toArray()['props']['seo']['robots'];
});

it('keeps thin pages out of search until they have real content, in the page and the sitemap alike', function () {
    $austin = ($this->team)('Lone Star Solar', 'austin');
    // Network not launched yet: the profile and the group page are noindex and not in the sitemaps.
    $this->get('/t/'.$austin->slug)->assertInertia(fn ($p) => $p->where('seo.robots', 'noindex, nofollow'));
    expect($this->get('/sitemaps/profiles.xml')->getContent())->not->toContain('/t/'.$austin->slug);

    $this->g->update(['status' => 'live']);
    Cache::flush();
    $this->get('/t/'.$austin->slug)->assertInertia(fn ($p) => $p->where('seo.robots', fn ($r) => str_starts_with($r, 'index, follow')));
    $this->get('/groups/'.$this->g->slug)->assertInertia(fn ($p) => $p->where('seo.robots', fn ($r) => str_starts_with($r, 'index, follow')));
    expect($this->get('/sitemaps/profiles.xml')->getContent())->toContain('/t/'.$austin->slug)
        ->and($this->get('/sitemaps/directory.xml')->getContent())->toContain('/groups/'.$this->g->slug)->toContain('/industries/solar/tx<');

    // Its only member goes private: the group and state pages have nothing to show, so they leave the index too.
    $austin->forceFill(['profile_public' => false])->save();
    Cache::flush();
    $this->get('/groups/'.$this->g->slug)->assertInertia(fn ($p) => $p->where('seo.robots', 'noindex, nofollow'));
    $xml = (string) $this->get('/sitemaps/directory.xml')->getContent();
    expect($xml)->not->toContain('/groups/'.$this->g->slug.'<')->not->toContain('/industries/solar/tx<');
});

it('names the site once in titles, and leaves it off when the title would be cut', function () {
    $this->get('/find-a-pro')->assertInertia(fn ($p) => $p->where('seo.title', 'Find a trusted pro | Team to Team Referrals'));
    $this->get('/about')->assertInertia(fn ($p) => $p->where('seo.title', 'About Team to Team Referrals'));
    $this->get('/industries')->assertInertia(fn ($p) => $p->where('seo.title', 'Industries | Team to Team Referrals'));
    expect((string) $this->get('/sitemaps/pages.xml')->getContent())->toContain('/find-a-pro</loc>');
});

it('lets the super admin switch AI crawlers, reflected in robots.txt and the audit log', function () {
    expect($this->get('/robots.txt')->getContent())->toContain("User-agent: GPTBot\n")->toContain("User-agent: PerplexityBot\nDisallow: /app");
    $this->actingAs($this->staff)->get('/admin/seo')->assertOk()->assertInertia(fn ($p) => $p->component('admin/Seo')->has('crawlers', 8)->where('crawlers.0.allowed', true));
    $this->actingAs($this->staff)->post('/admin/seo/crawlers', ['allowed' => ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'PerplexityBot']])->assertSessionHas('status', 'ai-crawlers-saved');
    $robots = (string) $this->get('/robots.txt')->getContent();
    expect($robots)->toMatch("#User-agent: GPTBot\n(Disallow: [^\n]+\n)*Disallow: /\n#")
        ->toMatch("#User-agent: ClaudeBot\n(Disallow: [^\n]+\n)*Disallow: /\n#")
        ->toMatch("#User-agent: PerplexityBot\n(Disallow: [^\n]+\n)*Allow: /\n#");
    expect(DB::table('activity_log')->where('event', 'ai-crawlers-changed')->exists())->toBeTrue();
    $this->actingAs($this->staff)->post('/admin/seo/crawlers', ['allowed' => ['EvilBot']])->assertSessionHasErrors('allowed.0');
    $member = User::factory()->create();
    $this->actingAs($member)->get('/admin/seo')->assertForbidden();
    // Before launch every crawler is blocked whatever is chosen.
    config(['seo.indexable' => false]);
    expect($this->get('/robots.txt')->getContent())->toBe("User-agent: *\nDisallow: /\n");
});

it('rebuilds llms.txt and the full content index whenever public pages change', function () {
    $full = (string) $this->get('/llms-full.txt')->assertOk()->getContent();
    expect($full)->toContain('## Key facts')->toContain('/find-a-pro')
        ->and($this->get('/llms.txt')->getContent())->toContain('/llms-full.txt');
    // A new public team appears in both without anyone regenerating them (seat and team changes announce new pages).
    $this->g->update(['status' => 'live']);
    $t = ($this->team)('Lone Star Solar', 'austin');
    expect((string) $this->get('/llms-full.txt')->getContent())->toContain('/t/'.$t->slug)
        ->and((string) $this->get('/llms.txt')->getContent())->toContain('/t/'.$t->slug);
    // Between changes they are served from the cache…
    DB::table('teams')->where('id', $t->id)->update(['slug' => 'renamed-quietly']);
    expect((string) $this->get('/llms-full.txt')->getContent())->toContain('/t/'.$t->slug);
    // …and rebuilt on the next change.
    PublicUrlsChanged::dispatch(['/t/renamed-quietly']);
    expect((string) $this->get('/llms-full.txt')->getContent())->toContain('/t/renamed-quietly');
});

it('links the first mention of each industry in an article, and relates articles and industry pages', function () {
    foreach (['solar', 'mortgage'] as $slug) {
        $i = Industry::where('slug', $slug)->firstOrFail();
        IndustryContent::save($i, IndustryContent::content($i), 'Reviewer', $this->staff);
        IndustryContent::publish($i, $this->staff);
    }
    Cache::forget('content:link-targets');
    $out = InternalLinks::apply('<h2>Solar basics</h2><p>Solar panels and solar loans. Ask a <a href="/x">Mortgage lending</a> pro.</p><p>Then mortgage lending rates.</p><pre>Solar</pre>');
    expect($out['html'])->toBe('<h2>Solar basics</h2><p><a href="/industries/solar">Solar</a> panels and solar loans. Ask a <a href="/x">Mortgage lending</a> pro.</p><p>Then <a href="/industries/mortgage">mortgage lending</a> rates.</p><pre>Solar</pre>')
        ->and(array_column($out['mentioned'], 'slug'))->toEqualCanonicalizing(['solar', 'mortgage']);

    $help = DB::table('blog_categories')->where('slug', 'help-centre')->value('id');
    $post = DB::table('posts')->where('category_id', '!=', $help)->orderBy('id')->first();
    DB::table('posts')->where('id', $post->id)->update(['reviewer' => 'Reviewer', 'body' => '<p>Going solar in a new city? A trusted solar installer there is one referral away, and every step is tracked.</p>']);
    Posts::publish(DB::table('posts')->find($post->id), null, $this->staff);
    Posts::publish(DB::table('posts')->where('category_id', $help)->orderBy('id')->first(), null, $this->staff);
    $this->get('/blog/'.$post->slug)->assertInertia(fn ($p) => $p->where('post.body', fn ($b) => str_contains($b, '<a href="/industries/solar">solar</a>'))
        ->where('related.industries.0.url', '/industries/solar')->has('related.articles', 1));
    $this->get('/industries/solar')->assertInertia(fn ($p) => $p->where('articles.0.url', '/blog/'.$post->slug));
});

it('gives each directory page its own signed preview image', function () {
    $this->g->update(['status' => 'live']);
    $t = ($this->team)('Lone Star Solar', 'austin');
    $image = null;
    $this->get('/industries/solar/tx/austin')->assertInertia(function ($p) use (&$image) {
        $image = (string) parse_url($p->toArray()['props']['seo']['image'], PHP_URL_PATH);

        return $p;
    });
    expect($image)->toStartWith('/og/');
    preg_match('#^/og/([A-Za-z0-9_-]+)\.([a-f0-9]{16})\.png$#', $image, $m);
    expect(json_decode(base64_decode(strtr($m[1], '-_', '+/')), true))->toMatchArray(['title' => 'Lone Star Solar', 'badge' => 'Seat holder']);
    expect(PageImage::svg(['eyebrow' => 'Solar', 'title' => 'A & B <Solar>']))->toContain('A &amp; B &lt;Solar&gt;');
    $this->get('/og/'.$m[1].'.0000000000000000.png')->assertNotFound();
    $r = $this->get($image);
    expect(in_array($r->status(), [200, 302], true))->toBeTrue(); // 302 to the brand image where the server can't draw
    foreach (['/groups/'.$this->g->slug, '/t/'.$t->slug, '/industries/solar/tx'] as $path) {
        $this->get($path)->assertInertia(fn ($p) => $p->where('seo.image', fn ($i) => str_contains($i, '/og/')));
    }
});

it('checks pages from the sitemaps and fails on any problem or missing page type', function () {
    // The rules themselves are covered in tests/Unit/Seo/PageAuditTest.php; this checks the command around them.
    $page = fn (string $path) => '<!doctype html><html><head><title>A page about solar referrals</title><meta name="description" content="A page that tells answer engines who, what, where, cost and how, plainly.">'
        .'<link rel="canonical" href="https://t2t.test'.$path.'"><meta name="robots" content="index, follow"><script type="application/ld+json">{"@type":"Organization","name":"T","url":"https://t2t.test"}</script></head>'
        .'<body><h1>Solar</h1><p>'.str_repeat('Plain answer words here. ', 6).'</p></body></html>';
    Http::fake([
        'https://t2t.test/sitemap.xml' => Http::response('<sitemapindex><sitemap><loc>https://t2t.test/sitemaps/pages.xml</loc></sitemap></sitemapindex>'),
        'https://t2t.test/sitemaps/pages.xml' => Http::response('<urlset><url><loc>https://t2t.test/</loc></url><url><loc>https://t2t.test/about</loc></url><url><loc>https://t2t.test/broken</loc></url></urlset>'),
        'https://t2t.test/llms.txt' => Http::response("- https://t2t.test/about\n- https://t2t.test/broken\n"),
        'https://t2t.test/llms-full.txt' => Http::response(''),
        'https://t2t.test/broken' => Http::response('', 500),
        'https://t2t.test/' => Http::response($page('/')),
        'https://t2t.test/about' => Http::response($page('/about')),
    ]);
    $this->artisan('t2t:seo:audit', ['--base' => 'https://t2t.test', '--require' => ['home,page']])
        ->expectsOutputToContain('/broken')->expectsOutputToContain('Returned HTTP 500')->assertFailed();
    expect(Cache::get('seo:audit:last'))->toMatchArray(['pages' => 3, 'failed' => 1]);
    $this->actingAs($this->staff)->get('/admin/seo')->assertInertia(fn ($p) => $p->where('audit.failed', 1));
    $this->artisan('t2t:seo:audit', ['--base' => 'https://t2t.test', '--require' => ['city']])->expectsOutputToContain('Page types not checked (none in the sitemap): city')->assertFailed();
});

it('checks links and duplicates across the site, keeps a history, and fails on broken links or duplicate titles', function () {
    $page = fn (string $path, string $title, string $links = '') => '<!doctype html><html><head><title>'.$title.'</title><meta name="description" content="A page that tells answer engines who, what, where, cost and how: '.$path.'.">'
        .'<link rel="canonical" href="https://t2t.test'.$path.'"><meta name="robots" content="index, follow"><script type="application/ld+json">{"@type":"Organization","name":"T","url":"https://t2t.test"}</script></head>'
        .'<body><main><h1>Solar</h1><p>'.str_repeat('Plain answer words here. ', 6).'</p>'.$links.'</main></body></html>';
    Http::fake([
        'https://t2t.test/sitemap.xml' => Http::response('<sitemapindex><sitemap><loc>https://t2t.test/sitemaps/pages.xml</loc></sitemap></sitemapindex>'),
        'https://t2t.test/sitemaps/pages.xml' => Http::response('<urlset><url><loc>https://t2t.test/</loc></url><url><loc>https://t2t.test/about</loc></url></urlset>'),
        'https://t2t.test/llms.txt' => Http::response("- https://t2t.test/about\n"),
        'https://t2t.test/llms-full.txt' => Http::response(''),
        'https://t2t.test/' => Http::response($page('/', 'Home of solar', '<a href="/about">About</a> <a href="/old-page">old</a> <a href="/pricing">Pricing</a>')),
        'https://t2t.test/about' => Http::response($page('/about', 'Home of solar')),
        'https://t2t.test/old-page' => Http::response('', 410),
        'https://t2t.test/pricing' => Http::response($page('/pricing', 'Pricing')),
    ]);
    $this->artisan('t2t:seo:audit', ['--base' => 'https://t2t.test', '--site' => true, '--trigger' => 'nightly'])
        ->expectsOutputToContain('Broken link to /old-page (HTTP 410) from /')
        ->expectsOutputToContain('Same title on /, /about')
        ->assertFailed();
    $row = DB::table('seo_checks')->latest('id')->first();
    expect($row->trigger)->toBe('nightly')->and(json_decode((string) $row->summary, true))->toMatchArray(['pages' => 2, 'broken_links' => 1, 'duplicate_titles' => 1]);
    $this->actingAs($this->staff)->get('/admin/seo')->assertInertia(fn ($p) => $p->where('history.0.broken_links', 1)->where('audit.broken./old-page.status', 410));
});
