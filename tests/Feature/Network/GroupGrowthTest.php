<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Agreements\AgreementDefaults;
use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Templates\FamilyDefaults;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('super_admin');
    $this->group = Group::where('slug', 'residential-agent-alliance')->firstOrFail();
    $this->industry = $this->group->industry;
});

it('lets a group override its industry’s form and pipeline, and reset back', function () {
    $t = app(IndustryTemplates::class);
    expect($t->resolve('stages', $this->industry, $this->group->id)['source'])->toBe('family');

    $stages = FamilyDefaults::stages('property');
    $stages[2]['label'] = 'Home tour';
    $this->actingAs($this->admin)->put('/admin/groups/residential-agent-alliance/stages', ['body' => $stages, 'acknowledge' => true])->assertSessionHas('status', 'template-saved');
    expect($t->resolve('stages', $this->industry, $this->group->id))->toMatchArray(['source' => 'group'])
        ->and($t->resolve('stages', $this->industry)['source'])->toBe('family');

    $this->get('/admin/groups/residential-agent-alliance/stages')->assertOk()->assertInertia(fn ($p) => $p->component('admin/industries/Stages')->where('scope.ownSource', 'group')->where('scope.basePath', '/admin/groups/residential-agent-alliance/stages'));
    $this->get('/admin/groups/residential-agent-alliance')->assertInertia(fn ($p) => $p->where('overrides.1.version', 1));

    $this->delete('/admin/groups/residential-agent-alliance/stages')->assertSessionHas('status', 'template-reset');
    expect($t->resolve('stages', $this->industry, $this->group->id)['source'])->toBe('family');
});

it('keeps members on the industry’s approved agreement until the group’s own version is approved', function () {
    $a = app(Agreements::class);
    $a->approve($this->industry, $this->admin->id, 'Counsel', 'OK');
    expect($a->forSigning($this->industry, $this->group->id))->toMatchArray(['version' => 1, 'scope' => 'industry']);

    $clauses = AgreementDefaults::for($this->industry->tier);
    $clauses[0]['text'] .= ' Group terms.';
    $this->actingAs($this->admin)->put('/admin/groups/residential-agent-alliance/agreement', ['clauses' => $clauses])->assertSessionHas('status', 'agreement-saved');
    expect($a->forSigning($this->industry, $this->group->id))->toMatchArray(['scope' => 'industry']);

    $this->post('/admin/groups/residential-agent-alliance/agreement/approve', ['counsel' => 'Counsel', 'note' => 'Group version OK'])->assertSessionHas('status', 'agreement-approved');
    expect($a->forSigning($this->industry, $this->group->id))->toMatchArray(['version' => 1, 'scope' => 'group'])
        ->and($a->forSigning($this->industry)['scope'])->toBe('industry');

    $this->get('/admin/groups/residential-agent-alliance/agreement')->assertInertia(fn ($p) => $p->where('scope.group', 'Residential Agent Alliance')->where('signingScope', 'group'));
});

it('only lets a super admin approve a group’s agreement', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole('staff');
    $staff->givePermissionTo('groups.manage');
    $this->actingAs($staff)->post('/admin/groups/residential-agent-alliance/agreement/approve', ['counsel' => 'X', 'note' => 'Y'])->assertForbidden();
});

it('opens the next group by region, copying rules, pricing and customisations', function () {
    $this->group->update(['pricing' => [...$this->group->pricing, 'seat_price' => 99]]);
    app(IndustryTemplates::class)->save('form', $this->industry, FamilyDefaults::form('property'), null, $this->group->id);

    $this->actingAs($this->admin)->post('/admin/groups/overflow', ['industry' => 'real-estate', 'region' => 'west'])->assertRedirect('/admin/groups/residential-agent-alliance-west');
    $west = Group::where('slug', 'residential-agent-alliance-west')->firstOrFail();
    expect($west->name)->toBe('Residential Agent Alliance — West')->and($west->region)->toBe('west')->and($west->parent_group_id)->toBe($this->group->id)
        ->and($west->is_launch)->toBeFalse()->and($west->pricing['seat_price'])->toBe(99)
        ->and(app(IndustryTemplates::class)->resolve('form', $this->industry, $west->id)['source'])->toBe('group');

    $this->post('/admin/groups/overflow', ['industry' => 'real-estate', 'region' => 'west'])->assertSessionHasErrors('region');
    $this->post('/admin/groups/overflow', ['industry' => 'real-estate', 'region' => 'national'])->assertRedirect('/admin/groups/residential-agent-alliance-ii');
    expect(Activity::where('event', 'group-opened')->count())->toBe(2);
    $this->get('/admin/groups/overflow')->assertOk()->assertInertia(fn ($p) => $p->component('admin/groups/Overflow')->has('regions', 4));
});

it('creates private networks that are never listed', function () {
    $owner = User::factory()->create(['email' => 'ana@sunpath.example']);
    $this->actingAs($this->admin)->post('/admin/groups/private', ['name' => 'SunPath Solar Branch Network', 'industry' => 'solar', 'owner_email' => 'ana@sunpath.example'])->assertRedirect('/admin/groups/sunpath-solar-branch-network');
    $p = Group::where('slug', 'sunpath-solar-branch-network')->firstOrFail();
    expect($p->is_private)->toBeTrue()->and($p->visibility)->toBe('invite_only')->and($p->isOwnedBy($owner))->toBeTrue();

    Industry::where('slug', 'solar')->update(['status' => 'live']);
    Group::query()->update(['status' => 'live']);
    expect(Group::listed()->where('slug', 'sunpath-solar-branch-network')->exists())->toBeFalse()
        ->and(Group::listed()->where('slug', 'solar-installers-network')->exists())->toBeTrue();

    $this->post('/admin/groups/private', ['name' => 'X', 'industry' => 'solar', 'owner_email' => 'nobody@example.com'])->assertSessionHasErrors('owner_email');
});

it('keeps the industry editors working exactly as before', function () {
    $this->actingAs($this->admin)->get('/admin/industries/real-estate/form')->assertOk()->assertInertia(fn ($p) => $p->where('scope.ownSource', 'industry')->where('scope.basePath', '/admin/industries/real-estate/form'));
    $this->get('/admin/industries/real-estate/agreement')->assertInertia(fn ($p) => $p->where('scope.group', null));
});
