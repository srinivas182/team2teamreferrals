<?php

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Platform\Files\PrivateFiles;
use App\Domains\Platform\Modules\Modules;
use App\Domains\Platform\Payments\Stripe;
use App\Domains\Platform\Providers\ApplyProviderSettings;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function sysAdmin(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('super_admin');

    return $u;
}

function connectStripe(): void
{
    $s = app(Settings::class);
    $s->set('payments.driver', 'stripe');
    $s->set('payments.stripe.publishable_key', 'pk_test_123');
    $s->set('payments.stripe.secret_key', 'sk_test_abc', secret: true);
    $s->set('payments.stripe.webhook_secret', 'whsec_test', secret: true);
}

it('connects Stripe from the admin and tests the connection', function () {
    Http::fake(['api.stripe.com/v1/account' => Http::response(['id' => 'acct_1', 'settings' => ['dashboard' => ['display_name' => 'Team to Team']]])]);
    $this->actingAs(sysAdmin())->put('/admin/system/payments', ['driver' => 'stripe', 'fields' => ['publishable_key' => 'pk_test_1', 'secret_key' => 'sk_test_1', 'webhook_secret' => 'whsec_1']])->assertSessionHasNoErrors();
    $this->post('/admin/system/check/payments')->assertSessionHas('checks', fn ($c) => $c['payments']['ok'] === true && str_contains($c['payments']['message'], 'Team to Team in test mode'));
});

it('accepts signed Stripe webhooks once and rejects bad or stale signatures', function () {
    connectStripe();
    $payload = json_encode(['id' => 'evt_1', 'type' => 'invoice.paid']);
    $t = time();
    $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_test');

    $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    expect(DB::table('webhook_events')->count())->toBe(1);

    $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$t.',v1=bad', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
    $old = $t - 600;
    expect(Stripe::validSignature($payload, 't='.$old.',v1='.hash_hmac('sha256', $old.'.'.$payload, 'whsec_test'), 'whsec_test'))->toBeFalse();
});

it('serves private files only through signed links to signed-in users', function () {
    app(ApplyProviderSettings::class)();
    Storage::fake('private');
    Storage::disk('private')->put('licenses/abc.pdf', 'PDF');
    $url = PrivateFiles::url('licenses/abc.pdf', 5);

    $this->get($url)->assertRedirect('/login');
    $user = User::factory()->create();
    $this->actingAs($user)->get($url)->assertOk();
    $this->get('/files/licenses/abc.pdf')->assertForbidden();
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

it('tests storage by writing, reading and deleting a file', function () {
    Storage::fake('private');
    $this->actingAs(sysAdmin())->post('/admin/system/check/storage')->assertSessionHas('checks', fn ($c) => $c['storage']['ok'] === true);
    expect(Storage::disk('private')->allFiles('.health'))->toBe([]);
});

it('meters AI spend and stops at the monthly cap', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ready']], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 500]])]);
    $s = app(Settings::class);
    $s->set('ai.driver', 'anthropic');
    $s->set('ai.anthropic.api_key', 'sk-ant', secret: true);
    $s->set('ai.anthropic.model', 'claude-haiku-5-5');
    $s->set('ai.anthropic.input_price', '1');
    $s->set('ai.anthropic.output_price', '5');
    $s->set('ai.anthropic.monthly_cap', '0.004');

    $r = app(AiProvider::class)->complete('hi', 'test');
    expect($r['text'])->toBe('ready')->and($r['cost_usd'])->toBe(0.0035);
    Http::assertSent(fn ($req) => $req->hasHeader('x-api-key', 'sk-ant') && $req['model'] === 'claude-haiku-5-5');

    app(AiProvider::class)->complete('hi', 'test');
    expect(fn () => app(AiProvider::class)->complete('hi', 'test'))->toThrow(RuntimeException::class, 'spend cap');
});

it('switches modules off everywhere: navigation flag, routes and push', function () {
    $user = User::factory()->create();
    $this->actingAs(sysAdmin())->put('/admin/system/modules', ['modules' => ['push' => false]])->assertSessionHas('status', 'settings-saved');
    expect(app(Modules::class)->enabled('push'))->toBeFalse();

    $this->actingAs($user)->get('/app/settings/notifications')->assertInertia(fn ($p) => $p->where('modules.push', false)->where('pushEnabled', false));
    $this->postJson('/app/push-subscriptions', ['endpoint' => 'https://push.example.com/a', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])->assertNotFound();
});

it('shows members a maintenance page while staff keep working', function () {
    $admin = sysAdmin();
    $this->actingAs($admin)->post('/admin/system/maintenance', ['on' => true])->assertSessionHas('status', 'maintenance-on');

    $this->actingAs(User::factory()->create())->get('/app')->assertStatus(503)->assertInertia(fn ($p) => $p->component('Error')->where('status', 503));
    auth()->logout();
    $this->get('/login')->assertOk();
    $this->get('/')->assertStatus(503);
    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('tests every provider at once and remembers the results', function () {
    Storage::fake('private');
    $this->actingAs(sysAdmin())->post('/admin/system/check-all')->assertSessionHas('checks', fn ($c) => array_keys($c) === ['mail', 'sms', 'payments', 'storage', 'ai'] && $c['storage']['ok'] === true && $c['payments']['ok'] === null);
    $this->get('/admin/system')->assertInertia(fn ($p) => $p->where('tab', 'overview')->where('overview.3.last.ok', true));
});

it('exports settings without secrets and imports only safe keys', function () {
    connectStripe();
    app(Settings::class)->set('general.company_name', 'T2T Ventures LLC');
    $res = $this->actingAs(sysAdmin())->get('/admin/system/export')->assertOk();
    expect($res->getContent())->toContain('T2T Ventures LLC')->toContain('pk_test_123')->not->toContain('sk_test_abc')->not->toContain('whsec_test');

    $file = UploadedFile::fake()->createWithContent('settings.json', json_encode(['settings' => [
        'general.company_name' => 'Imported Co', 'payments.stripe.secret_key' => 'sk_live_evil', 'app.key' => 'nope', 'modules.ai' => '1',
    ]]));
    $this->post('/admin/system/import', ['file' => $file])->assertSessionHas('status', 'settings-imported');
    $s = app(Settings::class);
    expect($s->get('general.company_name'))->toBe('Imported Co')->and($s->get('payments.stripe.secret_key'))->toBe('sk_test_abc')
        ->and($s->get('modules.ai'))->toBe('1')->and(DB::table('settings')->where('key', 'app.key')->exists())->toBeFalse();
});
