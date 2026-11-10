<?php

use App\Domains\Identity\Models\User;
use App\Domains\Seo\Aeo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    $this->shareId = (string) Str::ulid();
    $city = DB::table('cities')->where('is_enabled', true)->orderBy('id')->value('id');
    $group = DB::table('groups')->where('slug', 'solar-installers-network')->value('id');
    DB::table('reservations')->insert([
        ['share_id' => $this->shareId, 'group_id' => $group, 'city_id' => $city, 'email' => 'a@example.com', 'name' => 'Ana', 'business' => 'SunPath Solar', 'on_wall' => true, 'token_hash' => 'a', 'status' => 'confirmed', 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['share_id' => (string) Str::ulid(), 'group_id' => $group, 'city_id' => $city, 'email' => 'b@example.com', 'name' => 'Hidden', 'business' => 'Hidden Co', 'on_wall' => false, 'token_hash' => 'b', 'status' => 'confirmed', 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** Every indexable public page: a real description, a canonical URL and structured data. */
it('gives every public page answer-engine essentials', function (string $path, bool $faq) {
    config(['seo.indexable' => true]); // the site stays noindex until launch; check what launch will publish
    $url = str_replace('{share}', $this->shareId, $path);
    $this->get($url)->assertOk()->assertInertia(function ($p) use ($url, $faq) {
        $props = $p->toArray()['props'];
        $seo = $props['seo'];
        expect(mb_strlen($seo['description']))->toBeGreaterThanOrEqual(50)
            ->and($url === '/' ? rtrim($seo['canonical'], '/') === rtrim((string) config('seo.canonical_url'), '/') : str_ends_with($seo['canonical'], $url))->toBeTrue()
            ->and($seo['robots'])->toContain('index, follow')
            ->and($seo['schema'])->not->toBeEmpty();
        if ($faq) {
            $faqSchema = collect($seo['schema'])->firstWhere('@type', 'FAQPage');
            expect($faqSchema)->not->toBeNull();
            $schemaQs = array_column($faqSchema['mainEntity'], 'name');
            expect($schemaQs)->toBe(array_column(Aeo::faq(), 'q'))->and(array_column($props['faq'], 'q'))->toBe($schemaQs);
        }

        return $p;
    });
})->with([
    'home' => ['/', true],
    'reserve' => ['/reserve', true],
    'share page' => ['/reserve/c/{share}', false],
]);

it('publishes facts, answers and only public pages in llms.txt', function () {
    $txt = $this->get('/llms.txt')->assertOk()->getContent();
    expect($txt)->toContain('46 industries')->toContain('## Key facts')->toContain('## Answers')->toContain('### How much does a seat cost?')
        ->toContain('/reserve')->toContain('/reserve/c/'.$this->shareId)
        ->not->toContain('/app/')->not->toContain('/admin')->not->toContain('Hidden Co')->not->toContain('the platform charges flat fees only')->not->toContain('\\$');
});

it('lists the reserve page and opted-in share pages in the sitemap', function () {
    $xml = $this->get('/sitemaps/reserve.xml')->assertOk()->getContent();
    $hidden = DB::table('reservations')->where('on_wall', false)->value('share_id');
    expect($xml)->toContain('/reserve</loc>')->toContain('/reserve/c/'.$this->shareId)->not->toContain((string) $hidden);
});

it('keeps private pages out of search and answer engines', function () {
    $u = User::factory()->create();
    foreach (['/app', '/app/training', '/app/team', '/app/company'] as $path) {
        expect((string) $this->actingAs($u)->get($path)->headers->get('X-Robots-Tag'))->toContain('noindex');
    }
});
