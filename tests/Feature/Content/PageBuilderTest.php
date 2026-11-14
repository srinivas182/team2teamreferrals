<?php

use App\Domains\Content\Pages;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->editor = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->editor->assignRole(Permissions::STAFF);
    $this->editor->givePermissionTo('content.manage'); // content only: no billing, no admin settings
    $this->summary = 'Team to Team Referrals connects one verified pro per city per industry so members can pass out-of-area clients to teams they trust.';
    $this->landing = fn (array $overrides = []) => [
        'title' => 'Solar referrals', 'slug' => 'solar', 'template' => 'landing', 'answer_summary' => $this->summary,
        'seo' => ['description' => ''],
        'blocks' => [
            ['type' => 'hero', 'heading' => 'Refer out-of-area solar clients', 'subheading' => 'One verified installer per city.', 'cta_label' => 'Reserve your city', 'cta_url' => '/reserve'],
            ['type' => 'steps', 'heading' => 'How it works', 'items' => [['title' => 'Reserve', 'text' => 'Claim your city.'], ['title' => 'Refer', 'text' => 'Send a client in two taps.']]],
            ['type' => 'faq', 'heading' => 'Questions', 'items' => [['q' => 'What does it cost?', 'a' => '$10 a seat per month.'], ['q' => 'Who can join?', 'a' => 'Licensed, verified pros.']]],
            ['type' => 'pricing', 'plans' => [['name' => 'Seat', 'price' => '$10', 'period' => 'month', 'features' => "One city\nLeads from members", 'cta_label' => 'Start', 'cta_url' => '/register']]],
            ['type' => 'cta', 'heading' => 'Ready?', 'label' => 'Join', 'url' => '/register'],
        ],
        ...$overrides,
    ];
    $this->page = fn () => DB::table('cms_pages')->latest('id')->first();
});

it('DONE WHEN: a content editor builds and publishes a landing page using only the builder, and it meets the AEO rules', function () {
    config(['seo.indexable' => true]);
    $this->actingAs($this->editor)->post('/admin/content', ($this->landing)())->assertSessionHas('status', 'page-saved');
    $p = ($this->page)();
    $this->get('/solar')->assertNotFound(); // a draft isn't public
    $preview = $this->get("/admin/content/{$p->ulid}")->viewData('page')['props']['page']['preview'];
    $this->get($preview)->assertInertia(fn ($x) => $x->component('CmsPage')->where('preview.version', 1)->where('seo.robots', fn ($r) => str_contains((string) $r, 'noindex')));
    $this->post("/admin/content/{$p->ulid}/publish")->assertSessionHas('status', 'page-publish');
    $this->post('/logout');
    $this->get('/solar')->assertOk()->assertInertia(function ($x) {
        $props = $x->toArray()['props'];
        $seo = $props['seo'];
        expect($props['page']['summary'])->toBe($this->summary)                                          // answer first
            ->and(mb_strlen($seo['description']))->toBeGreaterThanOrEqual(50)->and($seo['canonical'])->toEndWith('/solar')->and($seo['robots'])->toContain('index, follow');
        $types = array_column($seo['schema'], '@type');
        expect($types)->toContain('Organization', 'WebPage', 'BreadcrumbList', 'FAQPage', 'HowTo', 'Service');
        $faq = collect($seo['schema'])->firstWhere('@type', 'FAQPage');
        $visible = array_column(collect($props['page']['blocks'])->firstWhere('type', 'faq')['items'], 'q');
        expect(array_column($faq['mainEntity'], 'name'))->toBe($visible)->toBe(['What does it cost?', 'Who can join?'])
            ->and($faq['mainEntity'][0]['acceptedAnswer']['text'])->toBe('$10 a seat per month.');
        $crumbs = collect($seo['schema'])->firstWhere('@type', 'BreadcrumbList');
        expect(array_column($crumbs['itemListElement'], 'name'))->toBe(['Home', 'Solar referrals']);
        expect(collect($seo['schema'])->firstWhere('@type', 'HowTo')['step'][1])->toMatchArray(['position' => 2, 'name' => 'Refer'])
            ->and(collect($seo['schema'])->firstWhere('@type', 'Service')['offers'][0])->toMatchArray(['price' => '10', 'priceCurrency' => 'USD'])
            ->and(collect($props['page']['blocks'])->firstWhere('type', 'pricing')['plans'][0]['features'])->toBe(['One city', 'Leads from members']);

        return $x;
    });
});

it('[6] won’t publish without an answer summary of the right length', function () {
    $this->actingAs($this->editor)->post('/admin/content', ($this->landing)(['answer_summary' => 'Too short.']));
    $this->post('/admin/content/'.($this->page)()->ulid.'/publish')->assertSessionHasErrors('answer_summary');
    expect(($this->page)()->status)->toBe('draft');
});

it('[8] keeps every version, rolls back exactly, and publishes on schedule', function () {
    $this->actingAs($this->editor)->post('/admin/content', ($this->landing)());
    $p = ($this->page)();
    $this->post("/admin/content/{$p->ulid}/publish");
    $this->post("/admin/content/{$p->ulid}", ($this->landing)(['title' => 'Solar referrals, second take']));
    $this->post("/admin/content/{$p->ulid}/publish");
    $this->get('/solar')->assertInertia(fn ($x) => $x->where('page.title', 'Solar referrals, second take'));
    $v1 = DB::table('cms_page_versions')->where(['page_id' => $p->id, 'number' => 1])->value('id');
    $this->post("/admin/content/{$p->ulid}/rollback", ['version' => $v1])->assertSessionHas('status', 'page-rollback');
    $this->get('/solar')->assertInertia(fn ($x) => $x->where('page.title', 'Solar referrals'));
    expect(DB::table('cms_page_versions')->where('page_id', $p->id)->count())->toBe(2);
    // Scheduled publishing.
    $this->post('/admin/content', ($this->landing)(['slug' => 'later', 'title' => 'Later']));
    $later = ($this->page)();
    $this->post("/admin/content/{$later->ulid}/publish", ['at' => now()->addHour()->format('Y-m-d H:i')])->assertSessionHas('status', 'page-publish-scheduled');
    $this->get('/later')->assertNotFound();
    $this->travel(61)->minutes();
    expect(Pages::publishDue())->toBe(1);
    $this->get('/later')->assertOk();
});

it('[9] needs "manage content"', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(Permissions::STAFF);
    $this->actingAs($staff)->get('/admin/content')->assertForbidden();
    $this->actingAs(User::factory()->create())->post('/admin/content', ($this->landing)())->assertForbidden();
    $this->actingAs($this->editor)->get('/admin/content')->assertOk();
    $this->get('/admin/billing')->assertForbidden(); // and nothing more
});

it('[10] removes unsafe content and refuses unsafe links, embeds and images without descriptions', function () {
    $this->actingAs($this->editor);
    $this->post('/admin/content', ($this->landing)(['blocks' => [['type' => 'rich_text', 'html' => '<h2>Hi</h2><p onclick="x()">Text <script>alert(1)</script><a href="javascript:alert(1)">bad</a> <a href="https://ok.example">ok</a></p><iframe src="https://evil.example"></iframe>']]]));
    $html = json_decode((string) DB::table('cms_page_versions')->latest('id')->value('blocks'), true)[0]['html'];
    expect($html)->toContain('<h2>Hi</h2>')->toContain('href="https://ok.example"')->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript')->not->toContain('iframe');
    $this->post('/admin/content', ($this->landing)(['slug' => 'bad-link', 'blocks' => [['type' => 'hero', 'heading' => 'Hi', 'cta_label' => 'Go', 'cta_url' => 'javascript:alert(1)']]]))->assertSessionHasErrors('blocks.0');
    $this->post('/admin/content', ($this->landing)(['slug' => 'bad-video', 'blocks' => [['type' => 'media', 'video' => 'https://evil.example/v/123']]]))->assertSessionHasErrors('blocks.0');
    $this->post('/admin/content', ($this->landing)(['slug' => 'no-alt', 'blocks' => [['type' => 'media', 'image' => '/img/team.webp']]]))->assertSessionHasErrors('blocks.0'); // no alt text
    $this->post('/admin/content', ($this->landing)(['slug' => 'good-video', 'blocks' => [['type' => 'media', 'video' => 'https://youtu.be/dQw4w9WgXcQ']]]));
    expect(json_decode((string) DB::table('cms_page_versions')->latest('id')->value('blocks'), true)[0]['video'])->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

it('[12] protects app addresses, redirects when an address changes, refuses loops, and hides unpublished pages', function () {
    $this->actingAs($this->editor);
    foreach (['app', 'admin/pages', 'login', 'reserve', 't/x', 'Bad Slug'] as $slug) {
        $this->post('/admin/content', ($this->landing)(['slug' => $slug]))->assertSessionHasErrors('slug');
    }
    $this->post('/admin/content', ($this->landing)());
    $p = ($this->page)();
    $this->post("/admin/content/{$p->ulid}/publish");
    $this->post("/admin/content/{$p->ulid}", ($this->landing)(['slug' => 'solar-referrals']));
    $this->post("/admin/content/{$p->ulid}/publish");
    $this->get('/solar')->assertRedirect('/solar-referrals')->assertStatus(301);
    expect(DB::table('redirects')->where('from_path', '/solar')->value('automatic'))->toBeTruthy();
    $this->post('/admin/content/redirects', ['from' => '/solar-referrals', 'to' => '/solar', 'code' => 301])->assertSessionHasErrors('to'); // a loop
    $this->post('/admin/content/redirects', ['from' => '/admin/x', 'to' => '/solar', 'code' => 301])->assertSessionHasErrors('from');
    $this->post('/admin/content/redirects', ['from' => '/old-pricing', 'to' => '/solar-referrals', 'code' => 302])->assertSessionHas('status', 'redirect-saved');
    $this->get('/old-pricing')->assertStatus(302);
    expect((int) DB::table('redirects')->where('from_path', '/old-pricing')->value('hits'))->toBe(1);
    $this->post("/admin/content/{$p->ulid}/unpublish");
    $this->get('/solar-referrals')->assertStatus(410); // was live, now taken down: "gone" (Sprint 43b)
    $this->get('/no-such-page')->assertNotFound();
    $this->get('/reserve')->assertOk(); // app pages are never shadowed
});
