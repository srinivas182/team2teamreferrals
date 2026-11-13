<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Notification::fake();
    $this->editor = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->editor->assignRole(Permissions::STAFF);
    $this->editor->givePermissionTo('content.manage');
    $this->summary = 'Team to Team Referrals connects one verified pro per city per industry so members can pass out-of-area clients to teams they trust.';
    // Fill in a draft page (as Sprint 38's content will) and publish it.
    $this->publish = function (string $slug, array $blocks, string $template = 'page') {
        $p = DB::table('cms_pages')->where('slug', $slug)->first();
        $title = DB::table('cms_page_versions')->where('id', $p->draft_version_id)->value('title');
        $this->actingAs($this->editor)->post("/admin/content/{$p->ulid}", ['title' => $title, 'slug' => $slug, 'template' => $template, 'answer_summary' => $this->summary, 'seo' => [], 'blocks' => $blocks]);
        $this->post("/admin/content/{$p->ulid}/publish")->assertSessionHas('status', 'page-publish');
    };
});

it('[2] creates the seven core pages once, as drafts with the right templates', function () {
    $this->actingAs($this->editor)->post('/admin/content/core')->assertSessionHas('status', 'core-pages-created');
    $this->post('/admin/content/core')->assertSessionHas('status', 'core-pages-exist');
    expect(DB::table('cms_pages')->orderBy('slug')->pluck('template', 'slug')->all())->toBe(['about' => 'page', 'contact' => 'page', 'faq' => 'page', 'how-it-works' => 'page', 'pricing' => 'landing', 'privacy' => 'legal', 'terms' => 'legal'])
        ->and(DB::table('cms_pages')->where('status', '!=', 'draft')->count())->toBe(0);
});

it('GUARD: shows only public menu links that open, and every link in public page code opens', function () {
    $this->get('/blog')->assertInertia(function ($p) {
        $hrefs = collect($p->toArray()['props']['menus']['header'])->pluck(1);
        expect($hrefs)->toContain('/blog')->not->toContain('/how-it-works')->not->toContain('/pricing');

        return $p;
    });
    $this->actingAs($this->editor)->post('/admin/content/core');
    ($this->publish)('how-it-works', [['type' => 'steps', 'heading' => 'How it works', 'items' => [['title' => 'Reserve', 'text' => 'Claim your city.']]]]);
    $this->post('/logout');
    $menus = $this->get('/blog')->viewData('page')['props']['menus'];
    $links = collect($menus['header'])->merge(collect($menus['footer'])->flatMap(fn ($g) => $g[1]))->pluck(1)->unique()->filter(fn ($u) => str_starts_with($u, '/'));
    expect($links)->toContain('/how-it-works');
    foreach ($links as $url) {
        expect($this->get($url)->getStatusCode())->not->toBe(404, "menu link {$url}");
    }
    // Links written into public page code (outside the menus) must open too.
    $files = ['layouts/PublicLayout.tsx', 'pages/Home.tsx', 'pages/Blog.tsx', 'pages/BlogPost.tsx', 'pages/CmsPage.tsx', 'components/cms/Blocks.tsx'];
    $broken = [];
    foreach ($files as $f) {
        preg_match_all('/href="(\/[a-z0-9\-\/]*)"/', (string) file_get_contents(resource_path('js/'.$f)), $m);
        foreach (array_unique($m[1]) as $url) {
            if ($this->get($url)->getStatusCode() === 404) {
                $broken[] = "{$f}: {$url}";
            }
        }
    }
    expect($broken)->toBe([]);
});

it('[14] protects the Contact form, saves genuine messages and tells staff', function () {
    $support = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $support->assignRole(Permissions::STAFF);
    $support->givePermissionTo('support.manage');
    $this->actingAs($this->editor)->post('/admin/content/core');
    ($this->publish)('contact', [['type' => 'contact_form', 'heading' => 'Send us a message', 'intro' => 'We answer within one business day.']]);
    $this->post('/logout');
    $token = $this->get('/contact')->assertOk()->viewData('page')['props']['contactToken'];
    $msg = ['name' => 'Ana Ruiz', 'email' => 'ana@example.org', 'topic' => 'joining', 'message' => 'How do I join the solar network in Austin?', 'started' => $token];
    $this->post('/contact', [...$msg, 'website_url' => 'http://spam.example'])->assertSessionHasErrors('message'); // the hidden field
    $this->post('/contact', $msg)->assertSessionHasErrors('message');                                              // too fast
    $this->travel(5)->seconds();
    $this->post('/contact', $msg)->assertSessionHas('status', 'contact-sent');
    expect(DB::table('contact_messages')->where('email', 'ana@example.org')->value('topic'))->toBe('joining');
    Notification::assertSentTo($support, InAppNotification::class, fn ($n) => $n->type === 'admin.contact');
    $this->post('/contact', $msg);
    $this->post('/contact', $msg);
    $this->post('/contact', $msg);
    $this->post('/contact', $msg)->assertStatus(429); // 5 an hour
    $this->actingAs($this->editor)->get('/admin/content/messages')->assertInertia(fn ($p) => $p->where('messages.0.topic', 'Joining a network'));
    $this->post('/admin/content/messages/'.DB::table('contact_messages')->value('id').'/handled');
    expect(DB::table('contact_messages')->value('status'))->toBe('handled');
});

it('[13] marks up only consented testimonials, in the member’s own words, and removes them when withdrawn', function () {
    $member = User::factory()->create(['first_name' => 'Maya', 'last_name' => 'Chen']);
    tap(Team::forLeader($member))->update(['name' => 'Chen Solar']);
    $this->actingAs($member)->post('/app/testimonial', ['quote' => 'We closed three out-of-area referrals in our first month.'])->assertSessionHasErrors('consent');
    $this->post('/app/testimonial', ['quote' => 'We closed three out-of-area referrals in our first month.', 'consent' => '1'])->assertSessionHas('status', 'testimonial-sent');
    $id = (int) DB::table('testimonials')->value('id');
    expect(DB::table('testimonials')->value('consent_text'))->toContain('may publish this quote');
    $page = fn ($items) => ['title' => 'Stories', 'slug' => 'stories', 'template' => 'page', 'answer_summary' => $this->summary, 'seo' => [], 'blocks' => [['type' => 'testimonials', 'items' => $items]]];
    $this->actingAs($this->editor)->post('/admin/content', $page([['consent_id' => $id]]))->assertSessionHasErrors('blocks.0'); // not approved yet
    $this->post("/admin/content/testimonials/{$id}/approve");
    $this->post('/admin/content', $page([['consent_id' => $id, 'quote' => 'Edited words the member never said'], ['quote' => 'A typed quote', 'author' => 'Someone']]));
    $p = DB::table('cms_pages')->latest('id')->first();
    $this->post("/admin/content/{$p->ulid}/publish");
    $this->post('/logout');
    $this->get('/stories')->assertInertia(function ($x) {
        $items = $x->toArray()['props']['page']['blocks'][0]['items'];
        $reviews = collect($x->toArray()['props']['seo']['schema'])->pluck('review')->filter()->flatten(1);
        expect($items[0])->toMatchArray(['quote' => 'We closed three out-of-area referrals in our first month.', 'author' => 'Maya Chen', 'business' => 'Chen Solar'])
            ->and($reviews)->toHaveCount(1)->and($reviews[0]['reviewBody'])->toBe('We closed three out-of-area referrals in our first month.');

        return $x;
    });
    $this->actingAs($member)->post("/app/testimonial/{$id}/withdraw")->assertSessionHas('status', 'testimonial-withdrawn');
    $this->post('/logout');
    $this->get('/stories')->assertInertia(fn ($x) => $x->where('page.blocks.0.items', [['quote' => 'A typed quote', 'author' => 'Someone', 'business' => '', 'consent_id' => null]])
        ->where('seo.schema', fn ($s) => collect($s)->pluck('review')->filter()->isEmpty()));
});

it('[15][16] publishes legal pages with their effective date, and a page with every block passes the AEO essentials', function () {
    $this->actingAs($this->editor)->post('/admin/content/core');
    ($this->publish)('terms', [['type' => 'rich_text', 'html' => '<h2>1. Agreement</h2><p>These terms govern your use of Team to Team Referrals.</p>']], 'legal');
    $this->get('/terms')->assertInertia(fn ($p) => $p->where('page.template', 'legal')->where('page.updated', now()->toDateString()));
    $all = [['type' => 'hero', 'heading' => 'Hi'], ['type' => 'features', 'items' => [['title' => 'A', 'text' => 'B']]], ['type' => 'steps', 'items' => [['title' => 'A', 'text' => 'B']]],
        ['type' => 'stats', 'items' => [['value' => '46', 'label' => 'industries']]], ['type' => 'testimonials', 'items' => [['quote' => 'Q', 'author' => 'A']]],
        ['type' => 'faq', 'items' => [['q' => 'Why?', 'a' => 'Because.']]], ['type' => 'pricing', 'plans' => [['name' => 'Seat', 'price' => '$10', 'features' => 'One city']]],
        ['type' => 'cta', 'heading' => 'Go', 'label' => 'Join', 'url' => '/register'], ['type' => 'rich_text', 'html' => '<p>Text</p>'],
        ['type' => 'media', 'image' => '/x.webp', 'alt' => 'A picture'], ['type' => 'contact_form', 'heading' => 'Write']];
    expect(count($all))->toBe(count(App\Domains\Content\Blocks::TYPES)); // every block type is covered
    $this->post('/admin/content', ['title' => 'Everything', 'slug' => 'everything', 'template' => 'landing', 'answer_summary' => $this->summary, 'seo' => [], 'blocks' => $all]);
    $this->post('/admin/content/'.DB::table('cms_pages')->latest('id')->value('ulid').'/publish');
    $this->post('/logout');
    $this->get('/everything')->assertOk()->assertInertia(function ($p) {
        $seo = $p->toArray()['props']['seo'];
        expect(mb_strlen($seo['description']))->toBeGreaterThanOrEqual(50)->and($seo['canonical'])->toEndWith('/everything')->and($seo['robots'])->toContain('index, follow')
            ->and(array_column($seo['schema'], '@type'))->toContain('Organization', 'WebPage', 'BreadcrumbList', 'FAQPage', 'HowTo', 'Service');

        return $p;
    });
});
