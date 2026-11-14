<?php

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Content\Posts;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Profiles\PublicProfiles;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\Monitoring\AiVisits;
use App\Domains\Seo\Monitoring\SearchConsole;
use App\Domains\Seo\Monitoring\Visibility;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['seo.indexable' => true, 'seo.canonical_url' => 'https://t2t.example']);
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    Cache::flush();
    $this->admin = User::factory()->create(['first_name' => 'Srini', 'last_name' => 'Rao', 'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole(Permissions::SUPER_ADMIN);
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->team = function (string $name, string $city, ?string $website = null) {
        $sv = app(Seats::class);
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name, 'website' => $website]);
        $sv->approve($sv->apply($this->g, $t, City::where('slug', $city)->firstOrFail(), 'L', null), null);
        PublicProfiles::ensureSlug($t);
        $t->forceFill(['profile_public' => true])->save();

        return $t->fresh();
    };
    $this->settings = app(Settings::class);
});

it('tells search engines a removed page is gone (410), and brings it back when it returns', function () {
    $t = ($this->team)('Lone Star Solar', 'austin');
    $this->get('/t/'.$t->slug)->assertOk();
    $t->forceFill(['profile_public' => false])->save();
    $this->get('/t/'.$t->slug)->assertStatus(410)->assertInertia(fn ($p) => $p->component('Error')->where('status', 410)->where('seo.robots', 'noindex, nofollow'));
    $t->forceFill(['profile_public' => true])->save();
    $this->get('/t/'.$t->slug)->assertOk();

    // A live post: renamed keeps its old address working; unpublished answers gone.
    $help = DB::table('blog_categories')->where('slug', 'help-centre')->value('id');
    $post = DB::table('posts')->where('category_id', $help)->orderBy('id')->first();
    Posts::publish($post, null, $this->admin);
    $post = DB::table('posts')->find($post->id);
    Posts::save($post, ['title' => $post->title, 'slug' => 'renamed-help', 'answer_summary' => $post->answer_summary, 'body' => $post->body, 'category_id' => $help, 'author_id' => null, 'reviewer' => null, 'cover_media_id' => null, 'seo' => []], $this->admin);
    $this->get('/blog/'.$post->slug)->assertRedirect('/blog/renamed-help');
    $this->actingAs($this->admin)->post('/admin/content/posts/'.$post->ulid.'/unpublish');
    auth()->logout();
    $this->get('/blog/renamed-help')->assertStatus(410);

    // A group closed answers gone; a group that was never public doesn't.
    $this->g->update(['status' => 'closed']);
    $this->get('/groups/'.$this->g->slug)->assertStatus(410);
    $draft = Group::where('status', 'draft')->first();
    $draft?->update(['is_private' => true]);
    expect(DB::table('gone_urls')->where('path', '/groups/'.$draft?->slug)->exists())->toBeFalse();

    // An industry page unpublished.
    $i = Industry::where('slug', 'mortgage')->firstOrFail();
    IndustryContent::save($i, IndustryContent::content($i), null, $this->admin);
    IndustryContent::publish($i, $this->admin);
    IndustryContent::unpublish($i, $this->admin);
    $this->get('/industries/mortgage')->assertStatus(410);
    $this->get('/never-existed-page')->assertNotFound();
});

it('counts AI crawler visits and people arriving from AI answers, page by page', function () {
    $this->get('/find-a-pro', ['User-Agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'])->assertOk();
    $this->get('/find-a-pro', ['User-Agent' => 'Mozilla/5.0 (compatible; PerplexityBot/1.0)'])->assertOk();
    $this->get('/find-a-pro', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130'])->assertOk(); // a person: not a crawler
    $this->get('/no-such-page', ['User-Agent' => 'GPTBot/1.2'])->assertNotFound(); // errors aren't counted
    $this->postJson('/ai-visit', ['path' => '/find-a-pro', 'referrer' => 'https://chatgpt.com/'])->assertNoContent();
    $this->postJson('/ai-visit', ['path' => '/groups', 'utm' => 'chatgpt.com'])->assertNoContent();
    $this->postJson('/ai-visit', ['path' => '/groups', 'referrer' => 'https://www.perplexity.ai/search?q=x'])->assertNoContent();
    $this->postJson('/ai-visit', ['path' => '/groups', 'referrer' => 'https://www.google.com/'])->assertNoContent(); // not an assistant
    $this->postJson('/ai-visit', ['path' => '/admin', 'referrer' => 'https://claude.ai/'])->assertNoContent(); // never the admin
    $this->postJson('/ai-visit', ['path' => 'not-a-path'])->assertUnprocessable();
    $r = AiVisits::report();
    expect($r['crawlers'])->toEqualCanonicalizing([['source' => 'GPTBot', 'visits' => 1, 'search' => false], ['source' => 'PerplexityBot', 'visits' => 1, 'search' => false]])
        ->and($r['referrals'])->toBe([['source' => 'ChatGPT', 'visits' => 2], ['source' => 'Perplexity', 'visits' => 1]])
        ->and(collect($r['pages'])->firstWhere('path', '/find-a-pro'))->toBe(['path' => '/find-a-pro', 'crawls' => 2, 'referrals' => 1]);
    expect(AiVisits::assistant('https://gemini.google.com/app'))->toBe('Gemini')->and(AiVisits::assistant('https://evilchatgpt.com/'))->toBeNull();
});

it('submits the sitemap to Google Search Console once set up, and reports what Google said', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'www.googleapis.com/*' => Http::sequence()->push('', 204)->push(['error' => ['message' => 'User does not have sufficient permission for site']], 403),
    ]);
    $gsc = app(SearchConsole::class);
    expect($gsc->enabled())->toBeFalse()->and($gsc->submit())->toMatchArray(['ok' => false, 'message' => 'Search Console is switched off or not set up.']);
    Http::assertNothingSent();

    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    $json = json_encode(['type' => 'service_account', 'client_email' => 'seo@t2t.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
    $this->actingAs($this->admin)->post('/admin/seo/search-console', ['enabled' => true, 'site' => 'example.com', 'credentials' => $json])->assertSessionHasErrors('site');
    $this->actingAs($this->admin)->post('/admin/seo/search-console', ['enabled' => true, 'site' => 'sc-domain:t2t.example', 'credentials' => '{"x":1}'])->assertSessionHasErrors('credentials');
    $this->actingAs($this->admin)->post('/admin/seo/search-console', ['enabled' => true, 'site' => 'sc-domain:t2t.example', 'credentials' => $json])->assertSessionHas('status', 'search-console-saved');
    expect($this->settings->masked('seo.gsc.credentials'))->not->toContain('PRIVATE');
    $this->actingAs($this->admin)->post('/admin/seo/search-console/submit')->assertSessionHas('status', 'sitemap-submitted');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === 'https://www.googleapis.com/webmasters/v3/sites/sc-domain%3At2t.example/sitemaps/https%3A%2F%2Ft2t.example%2Fsitemap.xml'
        && $r->hasHeader('Authorization', 'Bearer tok'));
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth2') && str_contains((string) $r->body(), 'jwt-bearer'));
    expect($gsc->submit())->toMatchArray(['ok' => false, 'message' => 'Google answered 403: User does not have sufficient permission for site']);
    $this->actingAs($this->admin)->get('/admin/seo')->assertInertia(fn ($p) => $p->where('searchConsole.last.ok', false)->where('searchConsole.enabled', true));
});

it('asks the answer engines that are on, and records whether they cite the site or a member', function () {
    ($this->team)('Lone Star Solar', 'austin', 'https://www.lonestarsolar.example/about');
    $i = Industry::where('slug', 'solar')->firstOrFail();
    IndustryContent::save($i, IndustryContent::content($i), null, $this->admin);
    IndustryContent::publish($i, $this->admin);
    $fakes = [
        'api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Try Team to Team Referrals, which lists one verified installer per city.',
            'annotations' => [['type' => 'url_citation', 'url' => 'https://t2t.example/industries/solar/tx/austin']]]]]]]),
        'api.perplexity.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Lone Star Solar is well reviewed in Austin [1].']]], 'citations' => ['https://lonestarsolar.example/']]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'web_search_tool_result', 'content' => [['type' => 'web_search_result', 'url' => 'https://www.energysage.example/austin']]],
            ['type' => 'text', 'text' => 'EnergySage compares installers in Austin.', 'citations' => [['url' => 'https://www.energysage.example/austin']]]]]),
        'serpapi.com/*' => Http::response(['organic_results' => [['link' => 'https://t2t.example/']]]), // no AI overview shown
    ];
    Http::fake($fakes);
    // Nothing is on: nothing is asked.
    $this->artisan('t2t:aeo:check')->expectsOutputToContain('No answer engine is switched on')->assertSuccessful();
    Http::assertNothingSent();

    // Switching on needs a key; keys are stored encrypted and shown masked.
    $this->actingAs($this->admin)->post('/admin/seo/engines', ['engines' => ['openai' => ['enabled' => true]], 'max_questions' => 10])->assertSessionHasErrors('engines.openai.key');
    $this->actingAs($this->admin)->post('/admin/seo/engines', ['max_questions' => 10, 'engines' => [
        'openai' => ['enabled' => true, 'key' => 'sk-openai-123456'], 'perplexity' => ['enabled' => true, 'key' => 'pplx-abcdef'],
        'anthropic' => ['enabled' => true, 'key' => 'sk-ant-xyz789', 'model' => 'claude-test'], 'google' => ['enabled' => true, 'key' => 'serp-111'],
    ]])->assertSessionHas('status', 'answer-engines-saved');
    expect($this->settings->masked('aeo.openai.key'))->not->toContain('openai-12');
    $this->actingAs($this->admin)->post('/admin/seo/questions', ['question' => 'Who installs solar in Austin, TX?'])->assertSessionHas('status', 'question-added');

    $this->actingAs($this->admin)->post('/admin/seo/visibility')->assertSessionHas('status', 'aeo-check-started'); // the queue runs at once in tests
    $month = now()->format('Y-m');
    $q = DB::table('aeo_questions')->where('question', 'Who installs solar in Austin, TX?')->value('id');
    $res = DB::table('aeo_results')->where('question_id', $q)->get()->keyBy('engine');
    expect((bool) $res['openai']->cited_platform)->toBeTrue()->and((bool) $res['openai']->cited_member)->toBeFalse()
        ->and((bool) $res['perplexity']->cited_member)->toBeTrue()->and((bool) $res['perplexity']->cited_platform)->toBeFalse()
        ->and((bool) $res['anthropic']->cited_platform)->toBeFalse()
        ->and((bool) $res['google']->cited_platform)->toBeFalse() // organic results aren't the AI answer
        ->and($res['google']->excerpt)->toBe('Google showed no AI Overview for this question.');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic') && $r['model'] === 'claude-test' && $r['tools'][0]['name'] === 'web_search' && $r->hasHeader('x-api-key', 'sk-ant-xyz789'));
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'openai') && $r['tools'] === [['type' => 'web_search']]);
    // Automatic questions come from the published industry and its member cities.
    expect(DB::table('aeo_questions')->where('source', 'auto')->pluck('question')->all())
        ->toContain('How do Solar referral fees work?')->toContain('How do I find a trusted installer in Austin, TX?');
    // Your own question is asked first; the cap counts questions.
    expect(DB::table('aeo_results')->where('month', $month)->distinct()->count('question_id'))->toBeLessThanOrEqual(10);

    // Running again this month asks nothing new; an engine error is recorded, not thrown.
    $this->artisan('t2t:aeo:check')->expectsOutputToContain('0 questions queued')->assertSuccessful();
    Visibility::record((int) $q, 'openai', $month, null, 'HTTP request returned status code 500');
    expect(DB::table('aeo_results')->where('question_id', $q)->where('engine', 'openai')->value('error'))->toContain('500');
    $this->artisan('t2t:aeo:check')->expectsOutputToContain('1 questions queued')->assertSuccessful(); // the failed one is asked again
    $this->actingAs($this->admin)->get('/admin/seo')->assertOk()->assertInertia(fn ($p) => $p->component('admin/Seo')->has('engines', 4)->where('engines.0.enabled', true)
        ->has('visibility.latest')->where('maxQuestions', 10));
    Visibility::record((int) $q, 'openai', '2026-01', ['text' => 'x', 'urls' => ['https://t2t.example/t/lone-star-solar']]);
    expect((bool) DB::table('aeo_results')->where(['question_id' => $q, 'month' => '2026-01'])->value('cited_member'))->toBeTrue();
});

it('keeps the SEO admin to super admins', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(Permissions::STAFF);
    $this->actingAs($staff)->get('/admin/seo')->assertForbidden();
    $this->actingAs($staff)->post('/admin/seo/engines', ['engines' => [], 'max_questions' => 5])->assertForbidden();
});
