<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Catalogue\CatalogueSync;
use App\Domains\Network\Industries\Catalogue\Readiness;
use App\Domains\Network\Industries\Http\AdminIndustryMessagesController;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
});

function launchStaff(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo('industries.manage');

    return $u;
}

it('creates all 46 launch industries with the agreed tiers and territories', function () {
    expect(Industry::count())->toBe(46)
        ->and(Industry::where('tier', 'A')->count())->toBe(30)->and(Industry::where('tier', 'B')->count())->toBe(12)->and(Industry::where('tier', 'C')->count())->toBe(4);
    $m = Industry::where('slug', 'mortgage')->firstOrFail();
    expect($m->terms())->toMatchArray(['pro' => 'loan officer', 'client' => 'borrower', 'referral' => 'introduction'])
        ->and(Industry::where('slug', 'title-escrow')->value('territory_unit'))->toBe('county');
});

it('adds industry-specific questions and stage names on top of the family defaults', function () {
    $solar = Industry::where('slug', 'solar')->firstOrFail();
    $t = app(IndustryTemplates::class);
    $form = $t->resolve('form', $solar);
    expect($form['source'])->toBe('industry')->and(collect($form['body'])->pluck('key'))->toContain('monthly_bill', 'roof_type')
        ->and(collect($form['body'])->last()['key'])->toBe('notes');
    expect(collect($t->resolve('stages', $solar)['body'])->pluck('label'))->toContain('Site survey', 'Install scheduled');
    expect($t->resolve('form', Industry::where('slug', 'pest-control')->firstOrFail())['source'])->toBe('family');
});

it('is safe to re-run: nothing changes, and admin edits are always kept', function () {
    $solar = Industry::where('slug', 'solar')->firstOrFail();
    $solar->update(['description' => 'Our own words', 'terminology' => [...$solar->terminology, 'pro' => 'solar consultant']]);
    app(IndustryTemplates::class)->save('form', $solar, app(IndustryTemplates::class)->resolve('form', $solar)['body'], null);

    expect(app(CatalogueSync::class)->run())->toMatchArray(['created' => 0, 'updated' => 0]);
    $solar->refresh();
    expect($solar->description)->toBe('Our own words')->and($solar->terminology['pro'])->toBe('solar consultant')
        ->and(DB::table('industry_templates')->where(['scope_id' => $solar->id, 'kind' => 'form'])->count())->toBe(2);
});

it('passes catalogue improvements through to values an admin never touched', function () {
    $hvac = Industry::where('slug', 'hvac')->firstOrFail();
    // Pretend the catalogue previously had a different icon and description.
    $hvac->forceFill(['icon' => 'Wind', 'description' => 'Old copy', 'catalogue_snapshot' => [...$hvac->catalogue_snapshot, 'icon' => 'Wind', 'description' => 'Old copy']])->save();
    $stats = app(CatalogueSync::class)->run();
    expect($stats['updated'])->toBe(1)->and($hvac->fresh()->icon)->toBe('AirVent')->and($hvac->fresh()->description)->toContain('Heating and cooling');
});

it('shows exactly what is left before each industry can go live', function () {
    $re = Industry::where('slug', 'real-estate')->firstOrFail();
    $row = collect(app(Readiness::class)->board())->firstWhere('slug', 'real-estate');
    expect($row['ready'])->toBeFalse()->and($row['checks']['agreement']['ok'])->toBeFalse()->and($row['checks']['compliance']['ok'])->toBeFalse();

    app(Agreements::class)->approve($re, null, 'Counsel', 'OK');
    $re->forceFill(['compliance_approved_at' => now(), 'compliance_note' => 'Approved'])->save();
    foreach (App\Domains\Network\Locations\Models\State::where('is_enabled', true)->get() as $s) {
        DB::table('industry_licensing')->updateOrInsert(['industry_id' => $re->id, 'state_id' => $s->id], ['required' => true, 'needs_review' => false, 'created_at' => now(), 'updated_at' => now()]);
    }
    $row = collect(app(Readiness::class)->board())->firstWhere('slug', 'real-estate');
    expect($row['ready'])->toBeTrue()->and(app(Readiness::class)->summary())->toBe(['ready' => 1, 'total' => 46]);

    $directory = collect(app(Readiness::class)->board())->firstWhere('slug', 'hospice');
    expect($directory['checks']['agreement']['ok'])->toBeNull();
});

it('shows the board and the dashboard progress only to staff who manage industries', function () {
    $this->actingAs(launchStaff())->get('/admin/industries/launch')->assertOk()->assertInertia(fn ($p) => $p->component('admin/industries/Launch')->has('board', 46)->where('summary.total', 46));
    $this->get('/admin')->assertInertia(fn ($p) => $p->where('launch.total', 46));

    $other = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $other->assignRole('staff');
    $this->actingAs($other)->get('/admin/industries/launch')->assertForbidden();
    $this->get('/admin')->assertInertia(fn ($p) => $p->where('launch', null));
});

it('translates admin message labels into the industry’s words', function () {
    $m = Industry::where('slug', 'mortgage')->firstOrFail();
    expect(AdminIndustryMessagesController::inTerms('Your referral was accepted', $m))->toBe('Your introduction was accepted')
        ->and(AdminIndustryMessagesController::inTerms('Referrals', $m))->toBe('Introductions')
        ->and(AdminIndustryMessagesController::inTerms('Your referral was accepted', Industry::where('slug', 'solar')->firstOrFail()))->toBe('Your referral was accepted');
});
