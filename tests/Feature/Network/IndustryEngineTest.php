<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Compliance;
use App\Domains\Network\Industries\LicensingCsv;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Models\IndustryLicensing;
use App\Domains\Network\Industries\Models\PlatformFeeRule;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Locations\Models\State;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]));

function makeIndustry(string $tier = 'A', array $extra = []): Industry
{
    return Industry::create(['slug' => 'solar', 'name' => 'Solar', 'family' => 'home', 'tier' => Tier::from($tier), 'territory_unit' => 'city',
        'terminology' => ['pro' => 'installer', 'pros' => 'installers', 'client' => 'homeowner', 'clients' => 'homeowners'], ...$extra]);
}

function superAdmin(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('super_admin');

    return $u;
}

function industryStaff(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo('industries.manage');

    return $u;
}

it('decides every tier rule in one place', function () {
    expect(Tier::Open->rules())->toBe(['memberReferralFees' => true, 'referralRouting' => true, 'consumerRequests' => true, 'valueGuarantee' => true, 'platformFee' => true, 'disclosure' => false])
        ->and(Tier::Restricted->rules())->toBe(['memberReferralFees' => false, 'referralRouting' => true, 'consumerRequests' => true, 'valueGuarantee' => true, 'platformFee' => false, 'disclosure' => true])
        ->and(Tier::Directory->rules())->toBe(['memberReferralFees' => false, 'referralRouting' => false, 'consumerRequests' => false, 'valueGuarantee' => false, 'platformFee' => false, 'disclosure' => true]);
});

it('creates draft industries from the admin and shows the builder with a territory preview', function () {
    $this->actingAs(industryStaff())->post('/admin/industries', [
        'name' => 'Solar', 'family' => 'home', 'tier' => 'A', 'territory_unit' => 'city', 'icon' => 'Sun', 'license_required' => true,
        'terminology' => ['pro' => 'installer', 'pros' => 'installers', 'client' => 'homeowner', 'clients' => 'homeowners'],
    ])->assertRedirect('/admin/industries/solar');
    expect(Industry::firstOrFail()->status)->toBe('draft');

    $this->get('/admin/industries/solar')->assertOk()->assertInertia(fn ($p) => $p->component('admin/industries/Show')
        ->where('territoryPreview.city', 5)->where('territoryPreview.state', 2)->where('territoryPreview.county', 4)->has('coverage', 2));
});

it('cannot go live without compliance approval, and only super admins can approve', function () {
    $industry = makeIndustry();
    $staff = industryStaff();
    $this->actingAs($staff)->post('/admin/industries/solar/status', ['live' => true])->assertSessionHasErrors('status');
    $this->post('/admin/industries/solar/approve', ['note' => 'Counsel memo 12 Nov 2026'])->assertForbidden();

    $this->actingAs(superAdmin())->post('/admin/industries/solar/approve', ['note' => 'short'])->assertSessionHasErrors('note');
    $this->post('/admin/industries/solar/approve', ['note' => 'Counsel memo 12 Nov 2026, all states'])->assertSessionHas('status', 'industry-approved');
    $this->actingAs($staff)->post('/admin/industries/solar/status', ['live' => true])->assertSessionHas('status', 'industry-live');
    expect($industry->fresh()->isLive())->toBeTrue();
});

it('removes approval, takes the industry offline and switches fees off when the tier changes', function () {
    $industry = makeIndustry();
    $admin = superAdmin();
    $c = app(Compliance::class);
    $c->approve($industry, $admin, 'Counsel memo');
    $c->goLive($industry, $admin);
    $c->unlockFees($admin, 'Brokerage licenses in all states, escrow routing approved');
    $c->saveFeeRule($industry, null, ['mode' => 'percent', 'percent' => 5], true, $admin);

    $this->actingAs($admin)->put('/admin/industries/solar', ['name' => 'Solar', 'family' => 'home', 'tier' => 'B', 'territory_unit' => 'city', 'icon' => 'Sun', 'license_required' => true,
        'terminology' => ['pro' => 'installer', 'pros' => 'installers', 'client' => 'homeowner', 'clients' => 'homeowners']])->assertSessionHasNoErrors();

    $industry->refresh();
    expect($industry->tier)->toBe(Tier::Restricted)->and($industry->isApproved())->toBeFalse()->and($industry->isLive())->toBeFalse()
        ->and(PlatformFeeRule::firstOrFail()->enabled)->toBeFalse();
});

it('keeps the platform fee locked until unlocked, and never allows it for Tier B or C', function () {
    $industry = makeIndustry();
    $admin = superAdmin();
    $c = app(Compliance::class);
    $c->approve($industry, $admin, 'Counsel memo');

    expect(fn () => $c->saveFeeRule($industry, null, ['mode' => 'percent', 'percent' => 5], true, $admin))->toThrow(DomainException::class, 'locked');
    $this->actingAs(industryStaff())->post('/admin/industries/fees/unlock', ['confirmation' => 'Licenses and escrow routing are approved'])->assertForbidden();

    $c->unlockFees($admin, 'Brokerage licenses in all states, escrow routing approved');
    $rule = $c->saveFeeRule($industry, null, ['mode' => 'percent', 'percent' => 5], true, $admin);
    expect($rule->enabled)->toBeTrue();

    $restricted = makeIndustry('B', ['slug' => 'mortgage', 'name' => 'Mortgage']);
    $c->approve($restricted, $admin, 'Counsel memo');
    expect(fn () => $c->saveFeeRule($restricted, null, ['mode' => 'flat', 'flat_cents' => 5000], true, $admin))->toThrow(DomainException::class, 'never allowed');

    $c->lockFees($admin);
    expect(PlatformFeeRule::where('enabled', true)->count())->toBe(0);
});

it('saves per-state licensing rules and validates license numbers against the pattern', function () {
    $industry = makeIndustry();
    $tx = State::where('code', 'TX')->firstOrFail();
    $this->actingAs(industryStaff())->put("/admin/industries/solar/licensing/{$tx->id}", ['required' => true, 'issuing_body' => 'TDLR', 'number_pattern' => '[(', 'lookup_url' => null])->assertSessionHasErrors('number_pattern');
    $this->put("/admin/industries/solar/licensing/{$tx->id}", ['required' => true, 'issuing_body' => 'TDLR', 'number_pattern' => '\d{5,6}', 'lookup_url' => 'https://www.tdlr.texas.gov/'])->assertSessionHas('status', 'licensing-saved');

    $rule = IndustryLicensing::firstOrFail();
    expect($rule->accepts('123456'))->toBeTrue()->and($rule->accepts('12-3456'))->toBeFalse()->and($rule->accepts(' 12345 '))->toBeTrue();
});

it('round-trips licensing rules through CSV for counsel', function () {
    makeIndustry();
    $csv = LicensingCsv::export();
    expect($csv)->toStartWith('industry,state,required')->toContain('solar,TX,,');

    $edited = "industry,state,required,issuing_body,number_pattern,lookup_url,notes\nsolar,TX,yes,TDLR,\\d{6},,Reviewed by counsel\nsolar,UT,no,,,,\nsolar,ZZ,yes,,,,\nsolar,TX,maybe,,,,\n";
    $this->actingAs(industryStaff())->post('/admin/industries/licensing/import', ['file' => UploadedFile::fake()->createWithContent('licensing.csv', $edited)])
        ->assertSessionHas('status', 'licensing-imported')->assertSessionHas('importResult', fn ($r) => $r['updated'] === 2 && count($r['skipped']) === 2);
    expect(IndustryLicensing::count())->toBe(2)->and(IndustryLicensing::whereHas('state', fn ($q) => $q->where('code', 'UT'))->value('required'))->toBeFalse();
});

it('keeps the industry builder behind its permission', function () {
    makeIndustry();
    $this->actingAs(User::factory()->create())->get('/admin/industries')->assertForbidden();
    $this->actingAs(industryStaff())->get('/admin/industries')->assertOk()->assertInertia(fn ($p) => $p->component('admin/industries/Index')->where('industries.0.coverage.of', 2));
});
