<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Seo\Events\PublicUrlsChanged;
use App\Domains\Support\Help;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Cache::flush();
    $this->editor = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->editor->assignRole(Permissions::STAFF);
    $this->editor->givePermissionTo('content.manage');
});

it('serves the help centre with topics, answers first, structured data and a sitemap', function () {
    $home = $this->get('/help')->assertOk()->viewData('page')['props'];
    expect(collect($home['categories'])->pluck('slug')->all())->toContain('getting-started', 'billing', 'partners')
        ->and(collect($home['seo']['schema'])->pluck('@type')->all())->toContain('FAQPage', 'BreadcrumbList')
        ->and($home['seo']['robots'])->not->toContain('noindex');

    $a = $this->get('/help/how-to-claim-a-seat')->assertOk()->viewData('page')['props'];
    expect($a['article']['summary'])->toStartWith('Open the network for your industry')
        ->and($a['article']['html'])->toContain('<ol>')->toContain('<strong>Claim this seat</strong>')
        ->and(collect($a['seo']['schema'])->pluck('@type')->all())->toContain('TechArticle', 'HowTo', 'FAQPage', 'BreadcrumbList')
        ->and(collect($a['seo']['schema'])->firstWhere('@type', 'HowTo')['step'])->toHaveCount(6)
        ->and(collect($a['related']))->toBeInstanceOf(Illuminate\Support\Collection::class);
    $topic = $this->get('/help/topic/referrals')->assertOk()->viewData('page')['props'];
    expect($topic['articles'])->toHaveCount(2)->and(collect($topic['seo']['schema'])->firstWhere('@type', 'FAQPage')['mainEntity'])->toHaveCount(2);
    $this->get('/help/no-such-article')->assertNotFound();
    $this->get('/help/topic/no-such-topic')->assertNotFound();

    $xml = (string) $this->get('/sitemaps/help.xml')->assertOk()->getContent();
    expect($xml)->toContain('/help/how-to-claim-a-seat')->toContain('/help/topic/billing')->toContain('/help<');
});

it('searches by every word, title matches first, and logs what found nothing', function () {
    $r = $this->get('/help/search?q=How+do+I+claim+a+seat')->assertOk()->viewData('page')['props'];
    expect($r['results'][0]['href'])->toBe('/help/how-to-claim-a-seat')->and($r['seo']['robots'])->toContain('noindex');
    expect(collect(Help::search('referral fee', null, 20, false))->pluck('slug')->first())->toBe('how-referral-fees-work');
    $this->get('/help/search?q=zebra+migration')->assertOk()->assertInertia(fn ($p) => $p->where('results', []));
    // Wildcards in the query are taken literally.
    expect(Help::search('%', null, 20, false))->toBe([]);
    expect(DB::table('help_searches')->where('results', 0)->pluck('query')->all())->toContain('zebra migration');
    // Search engines' visits aren't logged.
    $this->withServerVariables(['HTTP_USER_AGENT' => 'Googlebot/2.1'])->get('/help/search?q=robots+only');
    expect(DB::table('help_searches')->where('query', 'robots only')->exists())->toBeFalse();
});

it('keeps members-only and owners-only articles to those readers and out of search engines', function () {
    $cat = DB::table('help_categories')->where('slug', 'referrals')->value('id');
    foreach (['members' => 'members-tip', 'owners' => 'owner-tip'] as $aud => $slug) {
        DB::table('help_articles')->insert(['ulid' => strtolower((string) Str::ulid()), 'category_id' => $cat, 'slug' => $slug, 'title' => "Secret {$aud} tip", 'summary' => 'Only for some readers of this help centre.',
            'body_md' => 'x', 'body_html' => '<p>x</p>', 'audience' => $aud, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->get('/help/members-tip')->assertNotFound();
    $member = User::factory()->create();
    $this->actingAs($member)->get('/help/members-tip')->assertOk()->assertInertia(fn ($p) => $p->where('seo.robots', fn ($r) => str_contains((string) $r, 'noindex')));
    $this->actingAs($member)->get('/help/owner-tip')->assertNotFound();
    $this->actingAs($this->editor)->get('/help/owner-tip')->assertOk();
    expect((string) $this->get('/sitemaps/help.xml')->getContent())->not->toContain('members-tip')->not->toContain('owner-tip');
});

it('counts views and "was this helpful?" once per visitor a day, keeping the comment', function () {
    $browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0 Safari'];
    $this->withServerVariables($browser)->postJson('/help/how-to-claim-a-seat/seen')->assertNoContent();
    $this->withServerVariables($browser)->postJson('/help/how-to-claim-a-seat/seen');
    $this->withServerVariables(['HTTP_USER_AGENT' => 'bingbot'])->postJson('/help/how-to-claim-a-seat/seen');
    $this->withServerVariables($browser)->postJson('/help/how-to-claim-a-seat/feedback', ['helpful' => false])->assertOk();
    $this->withServerVariables($browser)->postJson('/help/how-to-claim-a-seat/feedback', ['helpful' => false, 'comment' => 'Doesn’t say what happens if my license is pending.']);
    $this->withServerVariables($browser)->postJson('/help/how-to-claim-a-seat/feedback', ['helpful' => true]); // a second vote the same day doesn't count
    $a = DB::table('help_articles')->where('slug', 'how-to-claim-a-seat')->first();
    expect((int) $a->views)->toBe(1)->and((int) $a->helpful_no)->toBe(1)->and((int) $a->helpful_yes)->toBe(0)
        ->and(DB::table('help_feedback')->value('comment'))->toBe('Doesn’t say what happens if my license is pending.');
    $this->actingAs($this->editor)->get('/admin/help')->assertInertia(fn ($p) => $p->where('comments.0.comment', 'Doesn’t say what happens if my license is pending.')
        ->where('articles', fn ($rows) => collect($rows)->firstWhere('slug', 'how-to-claim-a-seat')['helpful'] === 0));
});

it('lets editors write articles in Markdown, safely, with redirects when an address changes', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->get('/admin/help')->assertForbidden();
    $cat = (int) DB::table('help_categories')->where('slug', 'billing')->value('id');
    $base = ['title' => 'Can I get a refund?', 'slug' => 'refunds', 'summary' => 'Yes: staff can refund an invoice in part or in full from the billing admin.', 'category_id' => $cat,
        'audience' => 'public', 'status' => 'published', 'howto' => false];
    $this->actingAs($this->editor)->post('/admin/help/articles', [...$base, 'slug' => 'Bad Slug', 'body' => 'x'])->assertSessionHasErrors('slug');
    Event::fake([PublicUrlsChanged::class]);
    $this->post('/admin/help/articles', [...$base, 'body' => "Ask **support**.\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))"])->assertSessionHas('status', 'help-article-saved');
    $a = DB::table('help_articles')->where('slug', 'refunds')->first();
    expect($a->body_html)->toContain('<strong>support</strong>')->not->toContain('<script')->not->toContain('javascript:')
        ->and($a->published_at)->not->toBeNull()->and($a->review_due_on)->toBe(now()->addMonths(6)->toDateString());
    Event::assertDispatched(PublicUrlsChanged::class, fn ($e) => in_array('/help/refunds', $e->paths, true));
    $this->get('/help/refunds')->assertOk();
    // Renaming a published article keeps the old address working.
    $this->put('/admin/help/articles/'.$a->ulid, [...$base, 'slug' => 'refund-policy', 'body' => 'Ask support.'])->assertRedirect('/admin/help/articles/'.$a->ulid);
    expect(DB::table('redirects')->where(['from_path' => '/help/refunds', 'to_path' => '/help/refund-policy'])->exists())->toBeTrue();
    $this->postJson('/admin/help/preview', ['body' => "# Top\n\n## Hi"])->assertJsonPath('html', fn ($h) => str_contains((string) $h, '<h2>Hi</h2>') && ! str_contains((string) $h, '<h1>'));
    // Topics.
    $this->post('/admin/help/categories', ['name' => 'Integrations', 'slug' => 'integrations', 'sort' => 6])->assertSessionHas('status', 'help-category-saved');
    $this->delete('/admin/help/articles/'.$a->ulid)->assertRedirect('/admin/help');
    expect(DB::table('help_articles')->where('id', $a->id)->exists())->toBeFalse();
});
