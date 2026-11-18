<?php

/*
 * Sprint 53b: the installable app. The browser behaviour (offline, updates, sign-out, iPhone steps) is checked in a real
 * browser by scripts/ci/pwa.mjs; these check the pieces the server provides.
 */

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;

it('describes the app for Android, iPhone and desktop', function () {
    $m = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    expect($m)->toHaveKeys(['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'icons', 'shortcuts', 'screenshots'])
        ->and($m['display'])->toBe('standalone')->and(mb_strlen($m['short_name']))->toBeLessThanOrEqual(12)
        ->and(collect($m['icons'])->pluck('purpose')->all())->toContain('maskable');
    foreach ([...$m['icons'], ...$m['screenshots'], ...collect($m['shortcuts'])->flatMap(fn ($s) => $s['icons'])->all()] as $file) {
        expect(public_path(ltrim($file['src'], '/')))->toBeFile();
        [$w, $h] = getimagesize(public_path(ltrim($file['src'], '/')));
        expect("{$w}x{$h}")->toBe($file['sizes']);
    }
    $html = $this->get('/')->getContent();
    expect($html)->toContain('rel="manifest"')->toContain('apple-mobile-web-app-capable')->toContain('apple-mobile-web-app-title');
    preg_match_all('#href="(/brand/splash/[^"]+)"#', (string) $html, $splash);
    expect($splash[1])->not->toBeEmpty();
    foreach ($splash[1] as $src) {
        expect(public_path(ltrim($src, '/')))->toBeFile();
    }
});

it('keeps only member pages offline, never the admin, and has an offline page that needs no script', function () {
    $sw = (string) file_get_contents(public_path('sw.js'));
    expect($sw)->toContain("url.pathname.startsWith('/app/')")->toContain("startsWith('/app/billing')")->toContain("url.pathname === '/logout'")
        ->and($sw)->not->toContain("'/admin/'");
    $offline = (string) file_get_contents(public_path('offline.html'));
    expect($offline)->toContain('You’re offline')->not->toContain('<script')->not->toContain('onclick');
});

it('offers the app only after a member’s first referral', function () {
    $u = User::factory()->create(['email_verified_at' => now(), 'phone' => '+14355550177', 'phone_verified_at' => now()]);
    Team::forLeader($u);
    $this->get('/')->assertInertia(fn ($p) => $p->where('pwa', null));
    $this->actingAs($u)->get('/app')->assertInertia(fn ($p) => $p->where('pwa.sent', false));
    $other = User::factory()->create();
    // Only who sent it matters here, so the other required columns get placeholders.
    // (SQLite checks foreign keys at commit; each test's transaction is rolled back.)
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $id = DB::table('referrals')->insertGetId(['ulid' => strtolower((string) Illuminate\Support\Str::ulid()), 'sender_user_id' => $u->id] + collect(Schema::getColumns('referrals'))
        ->reject(fn ($c) => $c['nullable'] || $c['default'] !== null || $c['auto_increment'] || in_array($c['name'], ['ulid', 'sender_user_id'], true))
        ->mapWithKeys(fn ($c) => [$c['name'] => match (true) {
            str_ends_with($c['name'], '_id') => $other->id, str_contains(strtolower((string) $c['type_name']), 'int') => 1,
            str_contains(strtolower((string) $c['type_name']), 'json') => '{}', str_contains(strtolower((string) $c['type_name']), 'time') || str_contains(strtolower((string) $c['type_name']), 'date') => now(), default => 'x',
        }])->all());
    expect($id)->toBeGreaterThan(0);
    // Remembered for ten minutes in the session, so it costs no query on most requests.
    $this->get('/app')->assertInertia(fn ($p) => $p->where('pwa.sent', false));
    $this->travel(11)->minutes();
    $this->get('/app')->assertInertia(fn ($p) => $p->where('pwa.sent', true));
});
