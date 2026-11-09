<?php

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

it('reports health for uptime monitors', function () {
    Storage::fake('private');
    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonStructure(['checks' => ['database' => ['ok', 'ms'], 'cache', 'queue', 'storage']]);
});

it('shows a public status page', function () {
    Storage::fake('private');
    app(Settings::class)->set('status.incident', 'Text messages are delayed; we are on it.');
    $this->get('/status')->assertOk()->assertInertia(fn ($p) => $p->component('Status')->has('components', 4)->where('incident', 'Text messages are delayed; we are on it.')->where('components.0.ok', true));
});

it('gives every response a request ID, keeps a valid incoming one, and shows it on error pages', function () {
    $id = $this->get('/')->headers->get('X-Request-Id');
    expect($id)->toMatch('/^[0-9A-Z]{26}$/');
    expect($this->withHeader('X-Request-Id', 'lb-1234abcd')->get('/')->headers->get('X-Request-Id'))->toBe('lb-1234abcd');
    expect($this->withHeader('X-Request-Id', '<script>')->get('/')->headers->get('X-Request-Id'))->not->toBe('<script>');
    $this->withHeader('X-Request-Id', 'trace-5678efgh')->get('/no-such-page')->assertNotFound()->assertInertia(fn ($p) => $p->where('requestId', 'trace-5678efgh'));
});

it('does not keep settings between requests under Octane', function () {
    $a = app(Settings::class);
    app()->forgetScopedInstances(); // what Octane does between requests
    expect(app(Settings::class))->not->toBe($a);
});

it('opens the queue dashboard only to super admins with two-step sign-in', function () {
    $member = User::factory()->create();
    $super = User::factory()->create();
    $super->assignRole('super_admin');
    expect(Gate::forUser($member)->allows('viewHorizon'))->toBeFalse()->and(Gate::forUser($super)->allows('viewHorizon'))->toBeFalse();
    $super->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()])->save();
    expect(Gate::forUser($super->fresh())->allows('viewHorizon'))->toBeTrue();
});

/**
 * N+1 watchdog: key pages must stay within a fixed number of queries no matter how much data exists.
 * If a change makes a page query per row, this fails before it reaches production.
 */
it('keeps key pages within their query budgets', function (string $url, int $budget, ?string $role) {
    $user = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    if ($role) {
        $user->assignRole($role);
    }
    User::factory()->count(25)->create()->each(fn ($u) => $u->assignRole('staff'));
    activity('auth')->log('x');
    if ($role !== null || str_starts_with($url, '/app')) {
        $this->actingAs($user);
    }
    $this->withSession(['auth.password_confirmed_at' => time()]);
    DB::enableQueryLog();
    $this->get($url)->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($budget);
})->with([
    'home' => ['/', 6, null],
    'portal home' => ['/app', 22, null],
    'admin staff' => ['/admin/staff', 30, 'super_admin'],
    'admin audit' => ['/admin/audit', 30, 'super_admin'],
]);
