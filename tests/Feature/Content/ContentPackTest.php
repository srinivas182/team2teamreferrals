<?php

use App\Domains\Content\Blocks;
use App\Domains\Content\ContentPack;
use App\Domains\Content\Sections;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    $this->aeo = function (string $url) {
        $res = $this->get($url)->assertOk();
        $props = $res->viewData('page')['props'];
        expect(mb_strlen($props['seo']['description']))->toBeGreaterThanOrEqual(50, $url)->and($props['seo']['canonical'])->toEndWith($url)
            ->and($props['seo']['robots'])->toContain('index, follow')->and(array_column($props['seo']['schema'], '@type'))->toContain('Organization', 'WebPage')
            ->and(json_encode($props['page']).$props['seo']['description'])->not->toMatch('/\{[a-z_]+\}/'); // every placeholder filled

        return $props;
    };
});

it('DONE WHEN (site): a fresh install publishes finished core pages that pass the AEO rules', function () {
    foreach (['/how-it-works', '/pricing', '/about', '/faq', '/contact'] as $url) {
        ($this->aeo)($url);
    }
    $p = ($this->aeo)('/pricing');
    expect($p['page']['summary'])->toContain('$10 a month')->toContain('3 months free');
    $menus = $this->get('/blog')->viewData('page')['props']['menus'];
    expect(collect($menus['header'])->pluck(1))->toContain('/how-it-works', '/pricing');
    $llms = $this->get('/llms.txt')->getContent();
    expect($llms)->toContain('/pricing): Seats start at $10')->not->toMatch('/\{[a-z_]+\}/');
    expect(file_exists(public_path('illustrations/network.svg')))->toBeTrue()->and(file_exists(public_path('illustrations/steps.svg')))->toBeTrue();
});

it('takes figures from live settings, so a price change updates the pages and their structured data', function () {
    ($this->aeo)('/pricing'); // figures cached at $10
    foreach (Group::query()->get() as $g) {
        $g->update(['pricing' => [...$g->pricing, 'seat_price' => 12, 'trial_months' => 2]]); // through the model, as owners and schedules do
    }
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $this->actingAs($admin)->post('/admin/billing/settings', ['premium_price' => 21]);
    $this->post('/logout');
    $p = ($this->aeo)('/pricing');
    expect($p['page']['summary'])->toContain('$12 a month')->toContain('2 months free')
        ->and(json_encode($p['page']['blocks']))->toContain('$21');
    $faq = collect(($this->aeo)('/faq')['seo']['schema'])->firstWhere('@type', 'FAQPage');
    expect(json_encode($faq))->toContain('$12 a month');
});

it('never overwrites a page someone has edited', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $p = DB::table('cms_pages')->where('slug', 'about')->first();
    $this->actingAs($admin)->post("/admin/content/{$p->ulid}", ['title' => 'Our story', 'slug' => 'about', 'template' => 'page', 'answer_summary' => 'Our own words about Team to Team Referrals, written by our team for this page.', 'seo' => [], 'blocks' => [['type' => 'rich_text', 'html' => '<p>Ours.</p>']]]);
    $this->post("/admin/content/{$p->ulid}/publish");
    expect(ContentPack::install())->toBe(0);
    $this->get('/about')->assertInertia(fn ($x) => $x->where('page.title', 'Our story'));
});

it('offers 25+ valid sections that together pass the AEO rules', function () {
    $all = Sections::all();
    expect(count($all))->toBeGreaterThanOrEqual(25);
    foreach ($all as $key => $sec) {
        foreach ($sec['blocks'] as $n => $b) {
            expect(Blocks::clean($b, $n)['type'])->toBe($b['type'], $key);
        }
    }
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $blocks = Sections::blocks(array_values(array_diff(array_keys($all), ['city_intro', 'industry_intro', 'faq_industry']))); // site-wide sections
    $this->actingAs($admin)->post('/admin/content', ['title' => 'Every section', 'slug' => 'every-section', 'template' => 'landing', 'answer_summary' => 'Every ready-made section on one page, checked against the answer-engine rules for {site_name}.', 'seo' => [], 'blocks' => $blocks]);
    $this->post('/admin/content/'.DB::table('cms_pages')->where('slug', 'every-section')->value('ulid').'/publish');
    $this->post('/logout');
    ($this->aeo)('/every-section');
});

it('creates six industry pages in the industry’s own words, with the fee section its tier allows', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $solar = Group::where('slug', 'solar-installers-network')->firstOrFail()->industry;
    $mortgage = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail()->industry;
    $this->actingAs($admin)->post('/admin/content/industry-pages', ['industry' => $solar->slug])->assertSessionHas('status', 'industry-pages-created');
    $this->post('/admin/content/industry-pages', ['industry' => $solar->slug])->assertSessionHas('status', 'industry-pages-exist');
    $this->post('/admin/content/industry-pages', ['industry' => $mortgage->slug]);
    $content = fn (Industry $i) => DB::table('cms_pages as p')->join('cms_page_versions as v', 'v.id', '=', 'p.draft_version_id')->where('p.slug', 'like', 'networks/'.$i->slug.'%')->get(['p.slug', 'v.title', 'v.blocks', 'v.answer_summary']);
    $s = $content($solar);
    $m = $content($mortgage);
    expect($s)->toHaveCount(6)->and($m)->toHaveCount(6)
        ->and($s->pluck('slug')->all())->toContain('networks/'.$solar->slug, 'networks/'.$solar->slug.'/pricing', 'networks/'.$solar->slug.'/city-template');
    $solarText = $s->map(fn ($x) => $x->title.$x->answer_summary.$x->blocks)->implode(' ');
    $mortgageText = $m->map(fn ($x) => $x->title.$x->answer_summary.$x->blocks)->implode(' ');
    expect($solarText)->toContain($solar->name)->toContain($solar->terminology['pro'])->toContain('share of what the receiving team earns')->toContain('{seat_price}') // prices stay live
        ->and($mortgageText)->toContain($mortgage->terminology['pro'])->toContain('RESPA')->not->toContain('share of what the receiving team earns');
});
