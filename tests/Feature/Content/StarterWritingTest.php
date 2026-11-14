<?php

use App\Domains\Content\ContentPack;
use App\Domains\Content\Pack\HelpArticles;
use App\Domains\Content\Pack\LegalDrafts;
use App\Domains\Content\Pack\StarterPosts;
use App\Domains\Content\Pages;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    $this->editor = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->editor->assignRole(Permissions::STAFF);
    $this->editor->givePermissionTo('content.manage');
});

it('installs ten starter posts and the help articles as drafts, none of them public', function () {
    expect(StarterPosts::all())->toHaveCount(10);
    $slugs = [...array_column(StarterPosts::all(), 'slug'), ...array_column(HelpArticles::all(), 'slug')];
    expect(DB::table('posts')->whereIn('slug', $slugs)->count())->toBe(count($slugs))
        ->and(DB::table('posts')->whereIn('slug', $slugs)->where('status', '!=', 'draft')->count())->toBe(0)
        ->and(DB::table('posts')->whereIn('slug', $slugs)->whereNotNull('reviewer')->count())->toBe(0);
    $this->get('/blog/how-real-estate-referral-fees-work')->assertNotFound();
    expect(DB::table('blog_categories')->pluck('name')->all())->toContain('Help centre', 'Referral fees', 'Referral basics', 'Working referrals');
});

it('gives every post an answer summary in the AEO range and lists dated sources for its claims', function () {
    foreach (StarterPosts::all() as $p) {
        expect(mb_strlen($p['summary']))->toBeGreaterThanOrEqual(Pages::SUMMARY_MIN, $p['slug'])->toBeLessThanOrEqual(Pages::SUMMARY_MAX, $p['slug']);
        foreach ($p['sources'] as [$title, $url]) {
            expect($url)->toStartWith('https://')->and($title)->not->toBe('');
        }
        $body = (string) DB::table('posts')->where('slug', $p['slug'])->value('body');
        expect($body)->toContain('Facts checked '.StarterPosts::CHECKED);
        if ($p['sources'] !== []) {
            expect($body)->toContain('<h2>Sources</h2>')->toContain($p['sources'][0][1]);
        }
    }
    foreach (HelpArticles::all() as $a) {
        expect(mb_strlen($a['summary']))->toBeGreaterThanOrEqual(Pages::SUMMARY_MIN, $a['slug'])->toBeLessThanOrEqual(Pages::SUMMARY_MAX, $a['slug']);
    }
    // The legal claims cite the regulation itself.
    $respa = collect(StarterPosts::all())->firstWhere('slug', 'can-mortgage-lenders-pay-referral-fees');
    expect(array_column($respa['sources'], 1))->toContain('https://www.consumerfinance.gov/rules-policy/regulations/1024/14/');
});

it('describes fees as a share of the receiving team’s earnings, never the sale price', function () {
    $all = str_replace('never a share of the sale price', '', (string) json_encode([StarterPosts::all(), HelpArticles::all(), LegalDrafts::all()], JSON_UNESCAPED_UNICODE));
    expect($all)->not->toMatch('/(percent|%|share) of the (sale|sales|purchase) price/i');
    expect(json_encode(HelpArticles::all(), JSON_UNESCAPED_UNICODE))->toContain('never a share of the sale price');
});

it('shows “Review pending” and refuses to publish a starter post until a reviewer is named', function () {
    $this->actingAs($this->editor)->get('/admin/content/posts')->assertInertia(fn ($p) => $p->where('posts', fn ($posts) => collect($posts)->firstWhere('slug', 'how-real-estate-referral-fees-work')['reviewPending'] === true
        && collect($posts)->firstWhere('slug', 'help-getting-started')['reviewPending'] === false));
    $post = DB::table('posts')->where('slug', 'how-real-estate-referral-fees-work')->first();
    $this->post("/admin/content/posts/{$post->ulid}/publish")->assertSessionHasErrors('reviewer');
    $this->post("/admin/content/posts/{$post->ulid}", ['title' => $post->title, 'slug' => $post->slug, 'answer_summary' => $post->answer_summary, 'body' => $post->body,
        'category_id' => $post->category_id, 'reviewer' => 'Jordan Ellis, licensed broker', 'seo' => []]);
    $this->post("/admin/content/posts/{$post->ulid}/publish")->assertSessionHas('status', 'post-publish');
    $res = $this->get('/blog/how-real-estate-referral-fees-work')->assertOk();
    $props = $res->viewData('page')['props'];
    expect($props['post']['reviewer'])->toBe('Jordan Ellis, licensed broker')->and($props['post']['body'])->toContain('consumerfed.org');
});

it('fills live figures in help articles once published', function () {
    $help = DB::table('posts')->where('slug', 'help-claim-a-seat')->first();
    $this->actingAs($this->editor)->post("/admin/content/posts/{$help->ulid}/publish")->assertSessionHas('status', 'post-publish');
    $props = $this->get('/blog/help-claim-a-seat')->assertOk()->viewData('page')['props'];
    expect($props['post']['body'])->toContain('$10 a month')->not->toMatch('/\{[a-z_]+\}/')
        ->and($props['seo']['description'])->not->toMatch('/\{[a-z_]+\}/');
});

it('publishes the default Terms and Privacy with the company details filled in, and never overwrites edited pages', function () {
    app(App\Domains\Platform\Settings\Settings::class)->set('general.company_name', 'T2T Networks LLC');
    app(App\Domains\Platform\Settings\Settings::class)->set('general.governing_state', 'Florida');
    Illuminate\Support\Facades\Cache::forget('cms.tokens');
    foreach (['terms', 'privacy'] as $slug) {
        $page = DB::table('cms_pages')->where('slug', $slug)->first();
        expect($page->status)->toBe('published')->and($page->template)->toBe('legal');
        $html = (string) json_encode($this->get('/'.$slug)->assertOk()->viewData('page')['props']['page']['blocks']);
        expect($html)->toContain('T2T Networks LLC')->not->toContain('Draft for attorney')->not->toMatch('/\[[A-Z][^\]]*\]/')->not->toMatch('/\{[a-z_]+\}/');
    }
    expect((string) json_encode($this->get('/terms')->viewData('page')['props']['page']['blocks']))->toContain('laws of Florida');
    // A second run changes nothing (safe to run again).
    expect(ContentPack::installDrafts())->toBe([0, 0, 0]);
    $versions = DB::table('cms_page_versions')->count();
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    expect(DB::table('cms_page_versions')->count())->toBe($versions);
});
