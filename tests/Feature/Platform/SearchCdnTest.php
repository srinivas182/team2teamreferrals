<?php

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\Events\PublicUrlsChanged;
use Illuminate\Support\Facades\Http;

function staffWith(array $perms): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo($perms);

    return $u;
}

it('finds people only for staff allowed to manage them', function () {
    User::factory()->create(['first_name' => 'Priya', 'last_name' => 'Raman', 'email' => 'priya@example.com']);

    $this->actingAs(User::factory()->create())->getJson('/app/search?q=priya')->assertOk()->assertJsonPath('groups', []);
    $this->actingAs(staffWith(['audit.view']))->getJson('/app/search?q=priya')->assertJsonMissingPath('groups.0.items.0.subtitle');

    $this->actingAs(staffWith(['members.manage']))->getJson('/app/search?q=priya')
        ->assertJsonPath('groups.0.key', 'people')->assertJsonPath('groups.0.items.0.title', 'Priya Raman');
});

it('finds settings pages by keyword and hides admin pages from members', function () {
    $this->actingAs(User::factory()->create())->getJson('/app/search?q=quiet')->assertJsonPath('groups.0.items.0.url', '/app/settings/notifications');
    $this->getJson('/app/search?q=stripe')->assertJsonPath('groups', []);
    $this->actingAs(staffWith(['settings.manage']))->getJson('/app/search?q=stripe')->assertJsonPath('groups.0.items.0.url', '/admin/system');
});

it('ignores one-letter queries and requires sign-in', function () {
    $this->getJson('/app/search?q=pr')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/app/search?q=p')->assertJsonPath('groups', []);
});

it('lets a CDN cache the home page for guests only, without cookies', function () {
    $guest = $this->get('/');
    expect($guest->headers->get('Cache-Control'))->toContain('s-maxage=300')->toContain('public')
        ->and($guest->headers->getCookies())->toBe([]);

    $member = $this->actingAs(User::factory()->create())->get('/');
    expect($member->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('public');
});

it('purges changed public pages from Cloudflare', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
    $s = app(Settings::class);
    $s->set('cdn.driver', 'cloudflare');
    $s->set('cdn.cloudflare.zone_id', 'zone123');
    $s->set('cdn.cloudflare.api_token', 'cf-token', secret: true);
    config(['seo.canonical_url' => 'https://teamtoteamreferrals.com']);

    event(new PublicUrlsChanged(['/', '/solar/texas/austin']));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'zones/zone123/purge_cache') && $r['files'] === ['https://teamtoteamreferrals.com/', 'https://teamtoteamreferrals.com/solar/texas/austin'] && $r->hasHeader('Authorization', 'Bearer cf-token'));
});

it('checks the Cloudflare token from the admin', function () {
    Http::fake(['api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']])]);
    $admin = staffWith(['settings.manage']);
    $this->actingAs($admin)->put('/admin/system/cdn', ['driver' => 'cloudflare', 'fields' => ['zone_id' => 'z1', 'api_token' => 't1']])->assertSessionHasNoErrors();
    $this->post('/admin/system/check/cdn')->assertSessionHas('checks', fn ($c) => $c['cdn']['ok'] === true);
});
