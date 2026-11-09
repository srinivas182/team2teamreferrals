<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Catalogue\CatalogueSync;
use App\Domains\Network\Industries\Catalogue\Readiness;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\State;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
});

function groupsStaff(string $permission): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo($permission);

    return $u;
}

it('creates one launch group per industry with the agreed name and pricing', function () {
    expect(Group::count())->toBe(46)->and(Group::where('is_launch', true)->count())->toBe(46);
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    expect($g->name)->toBe('Solar Installers Network')->and($g->industry->slug)->toBe('solar')
        ->and($g->pricing)->toMatchArray(['seat_price' => 79, 'founding_price' => 59, 'trial_months' => 3, 'value_guarantee' => true])
        ->and($g->rules)->toMatchArray(['company_cap_pct' => 25, 'challenge_days' => 60]);
    expect(Group::whereHas('industry', fn ($q) => $q->where('slug', 'hospice'))->firstOrFail()->pricing['value_guarantee'])->toBeFalse();
});

it('never overwrites a group once created', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $g->update(['name' => 'Solar Pros of America', 'pricing' => [...$g->pricing, 'seat_price' => 99]]);
    app(CatalogueSync::class)->run();
    expect(Group::count())->toBe(46)->and($g->fresh()->name)->toBe('Solar Pros of America')->and($g->fresh()->pricing['seat_price'])->toBe(99);
});

it('suggests licensing for licensed industries but never counts it until an admin reviews it', function () {
    $re = Industry::where('slug', 'real-estate')->firstOrFail();
    $rows = DB::table('industry_licensing')->where('industry_id', $re->id)->get();
    expect($rows)->toHaveCount(2)->and($rows->every(fn ($r) => (bool) $r->needs_review))->toBeTrue()
        ->and($rows->first()->issuing_body)->toEndWith('real estate licensing authority')->and($rows->first()->lookup_url)->toBe('https://www.arello.com/');
    expect(DB::table('industry_licensing')->where('industry_id', Industry::where('slug', 'pest-control')->value('id'))->count())->toBe(0);

    $row = collect(app(Readiness::class)->board())->firstWhere('slug', 'real-estate');
    expect($row['checks']['licensing']['ok'])->toBeFalse()->and($row['checks']['licensing']['detail'])->toBe('0 of 2 states · 2 to review');

    $admin = groupsStaff('industries.manage');
    foreach (State::where('is_enabled', true)->get() as $s) {
        $this->actingAs($admin)->put("/admin/industries/real-estate/licensing/{$s->id}", ['required' => true, 'issuing_body' => "{$s->name} Real Estate Commission", 'number_pattern' => null, 'lookup_url' => 'https://example.gov/lookup', 'notes' => null])->assertSessionHasNoErrors();
    }
    $row = collect(app(Readiness::class)->board())->firstWhere('slug', 'real-estate');
    expect($row['checks']['licensing'])->toBe(['ok' => true, 'detail' => '2 of 2 states']);
});

it('keeps reviewed licensing when the catalogue runs again', function () {
    $re = Industry::where('slug', 'real-estate')->firstOrFail();
    DB::table('industry_licensing')->where('industry_id', $re->id)->update(['issuing_body' => 'Reviewed body', 'needs_review' => false]);
    app(CatalogueSync::class)->run();
    expect(DB::table('industry_licensing')->where('industry_id', $re->id)->pluck('issuing_body')->unique()->all())->toBe(['Reviewed body']);
});

it('shows the launch group on the readiness board', function () {
    expect(collect(app(Readiness::class)->board())->firstWhere('slug', 'solar')['checks']['group'])->toBe(['ok' => true, 'detail' => 'Solar Installers Network']);
});

it('downloads a counsel review pack for a whole tier', function () {
    $res = $this->actingAs(groupsStaff('industries.manage'))->get('/admin/industries/counsel-pack/tier-c.pdf')->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('tier-c-counsel-review-pack.pdf');
    expect(Activity::where('event', 'counsel-pack')->latest('id')->first()->properties['industries'])->toBe(4);
    $this->get('/admin/industries/counsel-pack/tier-x.pdf')->assertNotFound();
});

it('lists the groups for staff who manage groups only', function () {
    $this->actingAs(groupsStaff('groups.manage'))->get('/admin/groups')->assertOk()->assertInertia(fn ($p) => $p->component('admin/groups/Index')->has('groups', 46));
    $this->actingAs(groupsStaff('industries.manage'))->get('/admin/groups')->assertForbidden();
});
