<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('staff');
    $this->admin->givePermissionTo('groups.manage');
    $this->solar = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->hospice = Group::whereHas('industry', fn ($q) => $q->where('slug', 'hospice'))->firstOrFail();
});

function pricingPayload(array $over = []): array
{
    return ['when' => 'now', 'values' => [...['seat_price' => 89, 'founding_price' => 59, 'trial_months' => 3, 'value_guarantee' => true, 'annual_months_free' => 2, 'discounts' => ['2' => 10, '5' => 15, '20' => 25]], ...$over]];
}

it('shows a group with its pricing, rules, owners and health', function () {
    $this->actingAs($this->admin)->get('/admin/groups/solar-installers-network')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/groups/Show')->where('group.pricing.seat_price', 10)->where('group.industry.guaranteeAllowed', true)->where('health.owners', 0)->where('affected.seats', 0));
});

it('updates pricing now and audits before and after', function () {
    $this->actingAs($this->admin)->put('/admin/groups/solar-installers-network/pricing', pricingPayload())->assertSessionHas('status', 'group-saved');
    expect($this->solar->fresh()->pricing['seat_price'])->toBe(89);
    $a = Activity::where('event', 'group-pricing-changed')->firstOrFail();
    expect($a->properties['old']['seat_price'])->toBe(10)->and($a->properties['attributes']['seat_price'])->toBe(89);
});

it('rejects invalid pricing and keeps the value guarantee off in Tier C', function () {
    $this->actingAs($this->admin)->put('/admin/groups/solar-installers-network/pricing', pricingPayload(['founding_price' => 120]))->assertSessionHasErrors('founding_price');
    $this->put('/admin/groups/solar-installers-network/pricing', pricingPayload(['discounts' => ['2' => 30, '5' => 15, '20' => 25]]))->assertSessionHasErrors('discounts');
    $this->put("/admin/groups/{$this->hospice->slug}/pricing", pricingPayload())->assertSessionHasErrors('value_guarantee');
    $this->put("/admin/groups/{$this->hospice->slug}/pricing", pricingPayload(['value_guarantee' => false]))->assertSessionHas('status', 'group-saved');
});

it('schedules a change, notifies owners 30 days ahead, applies it on the day, and can be cancelled', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $this->solar->owners()->attach($owner->id);
    $date = now()->addDays(40)->toDateString();

    $this->actingAs($this->admin)->put('/admin/groups/solar-installers-network/rules', ['when' => 'scheduled', 'effective_on' => $date, 'values' => [...$this->solar->rules, 'accept_hours' => 12]])->assertSessionHas('status', 'group-change-scheduled');
    expect($this->solar->fresh()->rules['accept_hours'])->toBe(24);

    Artisan::call('t2t:groups:scheduled');
    expect(DB::table('group_scheduled_changes')->value('notified_at'))->toBeNull();

    $this->travel(11)->days();
    Artisan::call('t2t:groups:scheduled');
    expect(DB::table('group_scheduled_changes')->value('notified_at'))->not->toBeNull();
    Notification::assertSentTo($owner, App\Domains\Messaging\InAppNotification::class);

    $this->travel(30)->days();
    Artisan::call('t2t:groups:scheduled');
    expect($this->solar->fresh()->rules['accept_hours'])->toBe(12)->and(Activity::where('event', 'group-change-applied')->exists())->toBeTrue();

    $this->put('/admin/groups/solar-installers-network/pricing', ['when' => 'scheduled', 'effective_on' => now()->addDays(60)->toDateString(), 'values' => pricingPayload()['values']]);
    $id = DB::table('group_scheduled_changes')->whereNull('applied_at')->value('id');
    $this->delete("/admin/groups/solar-installers-network/scheduled/{$id}")->assertSessionHas('status', 'group-change-cancelled');
    $this->travel(70)->days();
    Artisan::call('t2t:groups:scheduled');
    expect($this->solar->fresh()->pricing['seat_price'])->toBe(10);
});

it('refuses to schedule changes in the past', function () {
    $this->actingAs($this->admin)->put('/admin/groups/solar-installers-network/rules', ['when' => 'scheduled', 'effective_on' => now()->toDateString(), 'values' => $this->solar->rules])->assertSessionHasErrors('effective_on');
});

it('edits details, uploads a photo, and only lets a group go live when its industry is live', function () {
    Storage::fake('public');
    $this->actingAs($this->admin)->post('/admin/groups/solar-installers-network', ['name' => 'Solar Installers Network', 'description' => 'New', 'status' => 'draft', 'visibility' => 'invite_only', 'photo' => UploadedFile::fake()->createWithContent('g.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))])->assertSessionHas('status', 'group-saved');
    $g = $this->solar->fresh();
    expect($g->visibility)->toBe('invite_only')->and($g->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($g->photo_path);

    $this->post('/admin/groups/solar-installers-network', ['name' => 'Solar Installers Network', 'status' => 'live', 'visibility' => 'open'])->assertSessionHasErrors('status');
    Industry::where('slug', 'solar')->update(['status' => 'live', 'compliance_approved_at' => now()]);
    $this->post('/admin/groups/solar-installers-network', ['name' => 'Solar Installers Network', 'status' => 'live', 'visibility' => 'open'])->assertSessionHasNoErrors();
});

it('adds and removes owners, who see their overview but nobody else does', function () {
    $owner = User::factory()->create(['email' => 'rosa@example.com']);
    $this->actingAs($this->admin)->post('/admin/groups/solar-installers-network/owners', ['email' => 'ROSA@example.com'])->assertSessionHas('status', 'group-owner-added');
    $this->post('/admin/groups/solar-installers-network/owners', ['email' => 'nobody@example.com'])->assertSessionHasErrors('email');

    $this->actingAs($owner)->get('/app/group/solar-installers-network')->assertOk()->assertInertia(fn ($p) => $p->component('app/group/Overview')->where('group.terms.pro', 'installer'));
    $this->actingAs(User::factory()->create())->get('/app/group/solar-installers-network')->assertForbidden();

    $this->actingAs($this->admin)->delete("/admin/groups/solar-installers-network/owners/{$owner->ulid}")->assertSessionHas('status', 'group-owner-removed');
    $this->actingAs($owner)->get('/app/group/solar-installers-network')->assertForbidden();
});

it('keeps group admin behind the groups permission', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole('staff');
    $this->actingAs($staff)->get('/admin/groups/solar-installers-network')->assertForbidden();
    $this->put('/admin/groups/solar-installers-network/pricing', pricingPayload())->assertForbidden();
});
