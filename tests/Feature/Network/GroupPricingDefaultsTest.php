<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('staff');
    $this->admin->givePermissionTo('groups.manage');
});

function bulkValues(array $over = []): array
{
    return [...['seat_price' => 12, 'founding_price' => 10, 'trial_months' => 2, 'value_guarantee' => true, 'annual_months_free' => 1, 'discounts' => ['2' => 0, '5' => 0, '20' => 0]], ...$over];
}

it('moves groups still on the old $79 sample price to $10 and leaves custom prices alone', function () {
    $solar = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $hvac = Group::where('slug', 'hvac-pros-network')->firstOrFail();
    $solar->update(['pricing' => [...$solar->pricing, 'seat_price' => 79, 'founding_price' => 59, 'discounts' => ['2' => 10, '5' => 15, '20' => 25]]]);
    $hvac->update(['pricing' => [...$hvac->pricing, 'seat_price' => 25]]);

    (require base_path('database/migrations/2026_11_22_000000_move_launch_groups_to_ten_dollars.php'))->up();

    expect($solar->fresh()->pricing)->toMatchArray(['seat_price' => 10, 'founding_price' => 10, 'discounts' => ['2' => 0, '5' => 0, '20' => 0]])
        ->and($hvac->fresh()->pricing['seat_price'])->toBe(25);
});

it('applies one pricing change to many groups and keeps the guarantee off for Tier C', function () {
    $this->actingAs($this->admin)->post('/admin/groups/bulk-pricing', ['groups' => ['solar-installers-network', 'hospice-providers-network'], 'when' => 'now', 'values' => bulkValues()])
        ->assertSessionHas('status', 'bulk-pricing-applied')->assertSessionHas('bulkNote', '2 groups updated. The value guarantee stays off for 1 Tier C group(s).');
    expect(Group::where('slug', 'solar-installers-network')->firstOrFail()->pricing)->toMatchArray(['seat_price' => 12, 'value_guarantee' => true])
        ->and(Group::where('slug', 'hospice-providers-network')->firstOrFail()->pricing)->toMatchArray(['seat_price' => 12, 'value_guarantee' => false]);
});

it('schedules a bulk change instead when asked', function () {
    $this->actingAs($this->admin)->post('/admin/groups/bulk-pricing', ['groups' => ['solar-installers-network', 'roofing-contractors-network'], 'when' => 'scheduled', 'effective_on' => now()->addDays(45)->toDateString(), 'values' => bulkValues()])
        ->assertSessionHas('status', 'bulk-pricing-scheduled');
    expect(DB::table('group_scheduled_changes')->count())->toBe(2)->and(Group::where('slug', 'solar-installers-network')->firstOrFail()->pricing['seat_price'])->toBe(10);
});

it('rejects invalid bulk pricing without changing any group', function () {
    $this->actingAs($this->admin)->post('/admin/groups/bulk-pricing', ['groups' => ['solar-installers-network', 'roofing-contractors-network'], 'when' => 'now', 'values' => bulkValues(['founding_price' => 50])])->assertSessionHasErrors('founding_price');
    expect(Group::whereIn('slug', ['solar-installers-network', 'roofing-contractors-network'])->get()->every(fn ($g) => $g->pricing['seat_price'] === 10))->toBeTrue();
    $this->post('/admin/groups/bulk-pricing', ['groups' => [], 'when' => 'now', 'values' => bulkValues()])->assertSessionHasErrors('groups');
});

it('keeps bulk pricing behind the groups permission', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole('staff');
    $this->actingAs($staff)->post('/admin/groups/bulk-pricing', ['groups' => ['solar-installers-network'], 'when' => 'now', 'values' => bulkValues()])->assertForbidden();
});
