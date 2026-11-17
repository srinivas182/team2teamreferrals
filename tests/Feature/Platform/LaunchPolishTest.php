<?php

use App\Domains\Content\Demo\DemoWorkspace;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
});

it('builds the home page from real figures, with labelled examples and a working industry finder', function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    $g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Lone Star Solar']);
    app(Seats::class)->approve(app(Seats::class)->apply($g, $team, City::where('slug', 'austin')->firstOrFail(), 'L', null), null);
    $p = $this->get('/')->assertOk()->viewData('page')['props'];
    expect($p['stats']['seats'])->toBe(1)->and($p['stats']['networks'])->toBe(1)->and($p['stats']['states'])->toBe(1)
        ->and($p['stats']['industries'])->toBe(DB::table('industries')->count())->and($p['stats']['acceptMinutes'])->toBeNull() // too few referrals to show a typical time
        ->and(collect($p['industries'])->firstWhere('slug', 'solar'))->toMatchArray(['live' => true, 'href' => '/groups/solar-installers-network'])
        ->and(collect($p['industries'])->firstWhere('slug', 'mortgage')['href'])->toBe('/groups?industry=mortgage')
        ->and($p['map']['slug'])->toBe('solar-installers-network')->and($p['map']['initial']['counts']['held'])->toBe(1)
        ->and($p['demoUrl'])->toBeNull()
        ->and(collect($p['seo']['schema'])->pluck('@type')->all())->toContain('Organization', 'WebSite', 'FAQPage');
    // The live demo link appears once a super admin sets it.
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole(Permissions::SUPER_ADMIN);
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(Permissions::STAFF);
    $staff->givePermissionTo('settings.manage');
    $this->actingAs($staff)->put('/admin/system/demo-link', ['url' => 'https://demo.example.com/demo'])->assertForbidden();
    $this->actingAs($admin)->put('/admin/system/demo-link', ['url' => 'http://demo.example.com'])->assertSessionHasErrors('url');
    $this->actingAs($admin)->put('/admin/system/demo-link', ['url' => 'https://demo.example.com/demo'])->assertSessionHas('status', 'settings-saved');
    $this->post('/logout');
    expect($this->get('/')->viewData('page')['props']['demoUrl'])->toBe('https://demo.example.com/demo');
    $this->get('/demo')->assertRedirect('https://demo.example.com/demo');
});

it('shows first-run tours until finished, lets people replay them or turn tips off', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/app')->assertInertia(fn ($p) => $p->where('tours', ['done' => [], 'off' => false]));
    $this->postJson('/app/tours/member-home/done')->assertOk();
    $this->postJson('/app/tours/member-home/done')->assertOk();
    $this->postJson('/app/tours/not-a-tour/done')->assertNotFound();
    $this->get('/app')->assertInertia(fn ($p) => $p->where('tours.done', ['member-home']));
    $this->post('/app/tours/tips', ['on' => false])->assertSessionHas('status', 'tips-off');
    expect(DB::table('users')->where('id', $u->id)->value('tips_off'))->toBe(1);
    $this->post('/app/tours/replay')->assertRedirect('/app');
    $this->get('/app')->assertInertia(fn ($p) => $p->where('tours', ['done' => [], 'off' => false]));
    $this->post('/logout');
    $this->get('/')->assertInertia(fn ($p) => $p->where('tours', null));
    $this->post('/app/tours/member-home/done')->assertRedirect('/login');
});

it('keeps the live demo to a demo install, and lets demo accounts look but not change accounts or billing', function () {
    $this->get('/demo')->assertNotFound(); // no demo install and no link set
    $this->post('/demo/enter', ['as' => 'team'])->assertNotFound();
    config(['app.demo_install' => true]);
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    $this->get('/demo')->assertOk()->assertInertia(fn ($p) => $p->component('Demo')->where('seo.robots', fn ($r) => str_contains((string) $r, 'noindex')));
    $this->post('/demo/enter', ['as' => 'team'])->assertRedirect('/app');
    $me = auth()->user();
    expect($me?->email)->toBe('demo2@'.DemoWorkspace::DOMAIN);
    $this->get('/app')->assertOk()->assertInertia(fn ($p) => $p->where('demo', true));
    // Account, security and billing changes are refused; looking around and demo actions are fine.
    $this->from('/app')->post('/app/settings/security/other-devices', ['password' => DemoWorkspace::PASSWORD])->assertRedirect('/app')->assertSessionHas('status', 'demo-blocked');
    $this->from('/app')->put('/user/password', ['current_password' => DemoWorkspace::PASSWORD, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHas('status', 'demo-blocked');
    expect(Hash::check(DemoWorkspace::PASSWORD, (string) $me?->fresh()?->password))->toBeTrue();
    $this->postJson('/app/billing/team/coupon', ['code' => 'X'])->assertForbidden();
    $this->postJson('/app/tours/member-home/done')->assertOk();
    $this->post('/logout')->assertRedirect();
    $this->post('/demo/enter', ['as' => 'owner']);
    expect(auth()->user()?->email)->toBe('demo1@'.DemoWorkspace::DOMAIN);
});
