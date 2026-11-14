<?php

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Content\Industry\IndustryPack;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Compliance;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Support\FormGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    $this->editor = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->editor->assignRole(Permissions::STAFF);
    $this->editor->givePermissionTo('content.manage');
    $this->industry = fn (string $slug) => Industry::query()->where('slug', $slug)->firstOrFail();
    $this->publish = function (string $slug, string $reviewer = 'Jordan Ellis, licensed broker') {
        $i = ($this->industry)($slug);
        IndustryContent::save($i, IndustryContent::content($i), $reviewer, $this->editor);
        IndustryContent::publish($i, $this->editor);

        return $i;
    };
});

it('installs the drafted hubs (26 of 46 so far) as drafts with review pending, and none is public', function () {
    expect(IndustryPack::all())->toHaveCount(26)
        ->and(array_diff(array_keys(IndustryPack::all()), Industry::query()->pluck('slug')->all()))->toBe([]); // every slug is a real industry
    foreach (array_keys(IndustryPack::all()) as $slug) {
        $r = IndustryContent::record(($this->industry)($slug));
        expect($r->status)->toBe('draft')->and($r->reviewer)->toBeNull();
        $this->get('/industries/'.$slug)->assertNotFound();
    }
    $this->get('/industries')->assertOk()->assertInertia(fn ($p) => $p->where('hubs', []));
    // Safe to run again: nothing is overwritten.
    expect(IndustryContent::install())->toBe(0);
});

it('writes every drafted hub to the publishing bar: only the named reviewer is missing', function () {
    foreach (array_keys(IndustryPack::all()) as $slug) {
        $i = ($this->industry)($slug);
        expect(array_keys(IndustryContent::problems($i, IndustryContent::content($i), null)))->toBe(['reviewer'], $slug)
            ->and(IndustryContent::problems($i, IndustryContent::content($i), 'A. Reviewer'))->toBe([], $slug);
    }
});

it('refuses to publish until a reviewer is named, then serves an answer-first hub with full structured data', function () {
    $i = ($this->industry)('solar');
    expect(fn () => IndustryContent::publish($i, $this->editor))->toThrow(Illuminate\Validation\ValidationException::class);
    ($this->publish)('solar', 'Sam Ortiz, NABCEP-certified installer');
    $res = $this->get('/industries/solar')->assertOk();
    $props = $res->viewData('page')['props'];
    expect($props['content']['summary'])->toStartWith('Solar referrals connect')
        ->and($props['reviewer'])->toBe('Sam Ortiz, NABCEP-certified installer')->and($props['reviewedOn'])->toBe(now()->toDateString())
        ->and($props['disclosure'])->toBeNull() // Tier A
        ->and(json_encode($props['content']).$props['seo']['description'])->not->toMatch('/\{[a-z_]+\}/');
    $schema = collect($props['seo']['schema']);
    expect($schema->pluck('@type')->all())->toContain('Organization', 'Service', 'WebPage', 'FAQPage', 'DefinedTermSet', 'BreadcrumbList')
        ->and($props['seo']['robots'])->toContain('index, follow')->and($props['seo']['canonical'])->toEndWith('/industries/solar');
    // The FAQ structured data matches the visible questions, and the review shows in the page data.
    $faq = $schema->firstWhere('@type', 'FAQPage');
    expect(array_column($faq['mainEntity'], 'name'))->toBe(array_column($props['content']['faqs'], 'q'));
    expect($schema->firstWhere('@type', 'WebPage')['reviewedBy']['name'])->toBe('Sam Ortiz, NABCEP-certified installer');
    // The tier fact comes from the tier, not the copy.
    expect($props['content']['facts']['tier'])->toContain('never a share of the sale price');
    $this->get('/industries')->assertInertia(fn ($p) => $p->where('hubs.0.slug', 'solar'));
});

it('shows the right notice on every Tier B and C hub, and none on Tier A', function () {
    foreach (['mortgage', 'insurance', 'personal-injury', 'real-estate', 'moving'] as $slug) {
        ($this->publish)($slug);
    }
    $d = fn (string $slug) => $this->get('/industries/'.$slug)->assertOk()->viewData('page')['props']['disclosure'];
    expect($d('mortgage'))->toStartWith('No referral fees')->toContain('Team to Team Referrals is not a loan officer');
    expect($d('insurance'))->toStartWith('No referral fees');
    expect($d('personal-injury'))->toStartWith('Directory only')->toContain('attorney advertising')->toContain('not legal advice');
    expect($d('real-estate'))->toBeNull()->and($d('moving'))->toBeNull();
    // Related industries link only to hubs that are live.
    $this->get('/industries/real-estate')->assertInertia(fn ($p) => $p->where('related', [['slug' => 'insurance', 'name' => 'Insurance (home, auto, life, health)'], ['slug' => 'mortgage', 'name' => 'Mortgage lending'], ['slug' => 'moving', 'name' => 'Moving companies']]));
});

it('takes a hub back to draft for review when its industry changes tier', function () {
    $i = ($this->publish)('solar');
    $admin = User::factory()->create();
    $admin->assignRole(Permissions::SUPER_ADMIN);
    app(Compliance::class)->changeTier($i, Tier::Restricted, $admin);
    expect(IndustryContent::record($i->fresh())->status)->toBe('draft');
    $this->get('/industries/solar')->assertNotFound();
    expect(IndustryContent::disclosure($i->fresh()))->toStartWith('No referral fees');
});

it('enforces the publishing checks: sources for costs, license lookups, FAQ count, https links and no fee offers in Tier B', function () {
    $solar = ($this->industry)('solar');
    $c = IndustryContent::content($solar);
    $p = fn (array $c, ?Industry $i = null) => array_keys(IndustryContent::problems($i ?? $solar, IndustryContent::normalise($c), 'R'));
    expect($p([...$c, 'sources' => []]))->toContain('sources');
    expect($p([...$c, 'lookups' => []]))->toContain('lookups');
    expect($p([...$c, 'faqs' => array_slice($c['faqs'], 0, 5)]))->toContain('faqs');
    expect($p([...$c, 'state_summary' => 'Find a verified solar installer anywhere in the country, quickly and safely.']))->toContain('state_summary');
    // A non-https address is dropped on save, and the empty link is then flagged.
    expect($p([...$c, 'sources' => [['title' => 'Insecure', 'url' => 'http://example.com']]]))->toContain('links');
    $m = ($this->industry)('mortgage');
    $mc = IndustryContent::content($m);
    expect($p([...$mc, 'how' => $mc['how'].' You can earn a referral fee on every closed loan.'], $m))->toContain('fees');
    // Markup never survives: content is plain text.
    IndustryContent::save($solar, [...$c, 'intro' => '<script>alert(1)</script>Plain words for the introduction that are long enough.'], null, $this->editor);
    expect(IndustryContent::content($solar)['intro'])->toBe('alert(1)Plain words for the introduction that are long enough.');
});

it('never lets a live hub be saved below the bar', function () {
    $i = ($this->publish)('moving');
    $c = IndustryContent::content($i);
    expect(fn () => IndustryContent::save($i, [...$c, 'faqs' => []], 'R', $this->editor))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(count(IndustryContent::content($i)['faqs']))->toBe(8);
});

it('fills state and city openings for any place', function () {
    $i = ($this->industry)('real-estate');
    expect(IndustryContent::placeSummary($i, 'Ohio'))->toContain('anywhere in Ohio')->toContain('active Ohio license')->not->toMatch('/\{[a-z_]+\}/');
    expect(IndustryContent::placeSummary($i, 'Ohio', 'Columbus'))->toStartWith('Buying or selling in Columbus, Ohio?')->toContain('Columbus seat');
});

it('adds published hubs and their facts to the sitemap and llms.txt', function () {
    ($this->publish)('mortgage');
    $llms = $this->get('/llms.txt')->getContent();
    expect($llms)->toContain('## Industry facts')->toContain('### Mortgage lending')->toContain('median closing costs of nearly $6,000')
        ->toContain('Referral fees are not allowed')->not->toContain('### Solar');
    $this->get('/sitemaps/content.xml')->assertOk()->assertSee('/industries/mortgage')->assertDontSee('/industries/solar');
});

it('sends a correction to the editors’ inbox, guarded against bots', function () {
    ($this->publish)('moving');
    $form = ['name' => 'Pat Lee', 'email' => 'pat@example.com', 'message' => 'The FMCSA search page has moved to a new address, see their site.'];
    $this->post('/industries/moving/correction', [...$form, 'started' => FormGuard::token()])->assertSessionHasErrors('message'); // too fast
    $this->travel(5)->seconds();
    $this->post('/industries/moving/correction', [...$form, 'started' => encrypt(now()->subSeconds(10)->timestamp)])->assertSessionHas('status', 'correction-sent');
    $row = DB::table('contact_messages')->where('topic', 'correction')->first();
    expect($row->page)->toBe('/industries/moving')->and($row->email)->toBe('pat@example.com');
    $this->actingAs($this->editor)->get('/admin/content/messages')->assertInertia(fn ($p) => $p->where('messages.0.topic', 'Correction')->where('messages.0.page', '/industries/moving'));
});

it('lets editors write, check, preview and publish from Admin → Website → Industries, and nobody else', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->get('/admin/content/industries')->assertForbidden();
    $this->actingAs($member)->get('/preview/industries/solar')->assertForbidden();
    $this->actingAs($this->editor)->get('/admin/content/industries')->assertOk()
        ->assertInertia(fn ($p) => $p->where('industries', fn ($rows) => collect($rows)->firstWhere('slug', 'solar')['state'] === 'review_pending'
            && collect($rows)->firstWhere('slug', 'auto-sales')['state'] === 'none'));
    $this->get('/admin/content/industries/solar')->assertInertia(fn ($p) => $p->where('problems', ['Review pending: name the expert who checked the facts.'])
        ->where('samples.city', fn ($s) => str_contains((string) $s, 'Austin, Texas')));
    $preview = $this->get('/preview/industries/solar')->assertOk()->viewData('page')['props'];
    expect($preview['preview'])->toBeTrue()->and($preview['seo']['robots'])->toContain('noindex');
    $this->post('/admin/content/industries/solar/publish')->assertSessionHasErrors('content');
    $this->post('/admin/content/industries/solar', ['content' => IndustryContent::content(($this->industry)('solar')), 'reviewer' => 'Sam Ortiz'])->assertSessionHas('status', 'industry-content-saved');
    $this->post('/admin/content/industries/solar/publish')->assertSessionHas('status', 'industry-content-publish');
    $this->get('/admin/content/industries')->assertInertia(fn ($p) => $p->where('industries', fn ($rows) => collect($rows)->firstWhere('slug', 'solar')['state'] === 'published'));
    $this->post('/admin/content/industries/solar/unpublish')->assertSessionHas('status', 'industry-content-unpublish');
    auth()->logout();
    $this->get('/industries/solar')->assertNotFound();
});

it('publishes the cost-data and review-standards pages on install, and never describes fees as a share of the sale price', function () {
    foreach (['/cost-data', '/editorial-standards'] as $url) {
        $props = $this->get($url)->assertOk()->viewData('page')['props'];
        expect(mb_strlen($props['seo']['description']))->toBeGreaterThan(50)->and(json_encode($props['page']))->not->toMatch('/\{[a-z_]+\}/');
    }
    $all = str_replace(['never a share of the sale price', 'never of the sale'], '', (string) json_encode(IndustryPack::all(), JSON_UNESCAPED_UNICODE));
    expect($all)->not->toMatch('/(percent|%|share) of the (sale|sales|purchase|closing) price/i');
});

it('adds a medical notice to Tier B and C care hubs, and links related hubs only to real industries', function () {
    foreach (['senior-living', 'in-home-care', 'hospice'] as $slug) {
        expect(IndustryContent::disclosure(($this->industry)($slug)))->toEndWith('not medical advice.');
    }
    expect(IndustryContent::disclosure(($this->industry)('hospice')))->toStartWith('Directory only')
        ->and(IndustryContent::disclosure(($this->industry)('estate-planning')))->toContain('attorney advertising')->not->toContain('medical');
    $slugs = Industry::query()->pluck('slug')->all();
    foreach (IndustryPack::all() as $slug => $c) {
        expect(array_diff($c['related'], $slugs))->toBe([], $slug)->and($c['related'])->not->toContain($slug);
    }
});

it('never offers referral fees in Tier B or C copy', function () {
    foreach (IndustryPack::all() as $slug => $c) {
        $i = ($this->industry)($slug);
        if ($i->tier === Tier::Open) {
            continue;
        }
        $copy = implode(' ', [$c['summary'], $c['intro'], $c['how'], $c['request'], ...array_column($c['faqs'], 'a')]);
        expect($copy)->not->toMatch('/(you|members) (can |may )?(earn|receive|get) (a )?referral fee|share of what the receiving/i', $slug);
    }
});

it('shows Industries in the site menu only once a hub is live', function () {
    $links = fn () => array_column(App\Domains\Content\Menus::forPublic()['header'], 1);
    expect($links())->not->toContain('/industries');
    ($this->publish)('roofing');
    expect($links())->toContain('/industries');
    IndustryContent::unpublish(($this->industry)('roofing'), $this->editor);
    expect($links())->not->toContain('/industries');
});
