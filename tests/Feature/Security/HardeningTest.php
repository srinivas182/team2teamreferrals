<?php

/*
 * Sprint 53a security review: regression tests for the OWASP checks (security.txt, brute-force limits, cookies,
 * escaping of people's own text, uploads that aren't what they claim, and the admin's two-step sign-in).
 */

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('publishes security.txt with a contact and an expiry in the future (RFC 9116)', function () {
    $body = $this->get('/.well-known/security.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();
    expect($body)->toMatch('/^Contact: mailto:\S+@\S+$/m')->toContain('Canonical: ');
    preg_match('/^Expires: (.+)$/m', (string) $body, $m);
    expect(strtotime($m[1]))->toBeGreaterThan(time() + 300 * 86400);
    app(Settings::class)->set('general.security_email', 'security@example.com');
    $this->get('/.well-known/security.txt')->assertSee('Contact: mailto:security@example.com', false);
});

it('locks out password guessing after five tries a minute', function () {
    $u = User::factory()->create(['password' => bcrypt('the-right-password-1')]);
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong-'.$i]);
    }
    $this->post('/login', ['email' => $u->email, 'password' => 'the-right-password-1'])->assertStatus(429);
    $this->assertGuest();
});

it('keeps session cookies away from scripts and other sites, and HTTPS-only in production', function () {
    expect(config('session.http_only'))->toBeTrue()->and(config('session.same_site'))->toBe('lax');
    $config = require base_path('config/session.php');
    expect($config['secure'])->toBe(env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'));
});

it('escapes a member’s own text wherever the server builds HTML from it', function () {
    $u = User::factory()->create(['email_verified_at' => now(), 'phone' => '+14355550123', 'phone_verified_at' => now()]);
    tap(Team::forLeader($u))->update(['name' => '<img src=x onerror=alert(1)>']);
    $signature = $this->actingAs($u)->get('/app/share')->assertOk()->viewData('page')['props']['signature'];
    expect($signature)->toContain('&lt;img src=x onerror=alert(1)&gt;')->not->toContain('<img src=x');
});

it('refuses uploads that only pretend to be images', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(Permissions::STAFF);
    $staff->givePermissionTo('content.manage');
    $fake = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "hi"; ?>');
    $this->actingAs($staff)->post('/admin/content/media', ['file' => $fake, 'alt' => 'A photo of a house'])->assertSessionHasErrors('file');
    expect(DB::table('media')->count())->toBe(0);
});

it('keeps staff without two-step sign-in out of the admin', function () {
    $staff = User::factory()->create();
    $staff->assignRole(Permissions::STAFF);
    $staff->givePermissionTo('settings.manage');
    $r = $this->actingAs($staff)->get('/admin/system');
    expect($r->getStatusCode())->toBeIn([302, 403])->and((string) $r->headers->get('Location'))->not->toContain('/admin/system');
});

it('publishes an accessibility statement that is in the sitemap and the footer', function () {
    $p = $this->get('/accessibility')->assertOk()->viewData('page')['props'];
    expect($p['answer'])->toContain('WCAG')->and(collect($p['seo']['schema'])->pluck('@type')->all())->toContain('Organization', 'FAQPage')
        ->and($p['reviewed'])->toBe(App\Domains\Content\Http\AccessibilityController::REVIEWED);
    $this->get('/sitemaps/press.xml')->assertSee('/accessibility');
    expect(collect(App\Domains\Content\Menus::DEFAULT_FOOTER)->flatMap(fn ($g) => $g[1])->pluck(1)->all())->toContain('/accessibility');
});
