<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Templates\FamilyDefaults;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Industries\Templates\Schemas;
use App\Domains\Network\Industries\Tier;
use Illuminate\Support\Facades\Validator;

function templIndustry(string $family = 'home'): Industry
{
    return Industry::create(['slug' => 'solar', 'name' => 'Solar', 'family' => $family, 'tier' => Tier::Open, 'territory_unit' => 'city',
        'terminology' => ['pro' => 'installer', 'pros' => 'installers', 'client' => 'homeowner', 'clients' => 'homeowners']]);
}

function templAdmin(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo('industries.manage');

    return $u;
}

it('gives every family a valid default form and pipeline with no errors', function (string $family) {
    $check = Schemas::checkForm(FamilyDefaults::form($family));
    // Legal and care deliberately show no estimated value (see FamilyDefaults).
    $expectedWarnings = in_array($family, ['legal', 'care'], true) ? 1 : 0;
    expect($check['errors'])->toBe([])->and($check['warnings'])->toHaveCount($expectedWarnings)
        ->and(Schemas::checkStages(FamilyDefaults::stages($family))['errors'])->toBe([]);
})->with(array_keys(Industry::FAMILIES));

it('catches broken forms and warns about missing roles and unprotected contact fields', function () {
    $bad = [['key' => 'x', 'label' => '', 'type' => 'choice', 'options' => ['only one']], ['key' => 'x', 'label' => 'Dup', 'type' => 'bogus']];
    $r = Schemas::checkForm($bad);
    expect($r['errors'])->toContain('Field 1: the key must be 2–40 lowercase letters, numbers or underscores.')
        ->and(implode(' ', $r['errors']))->toContain('client_name')->toContain('choose a field type');

    $noRoles = [['key' => 'client_name', 'label' => 'Name', 'type' => 'text', 'sensitive' => true], ['key' => 'client_phone', 'label' => 'Phone', 'type' => 'text', 'sensitive' => true], ['key' => 'work_email', 'label' => 'Work email', 'type' => 'text']];
    $w = Schemas::checkForm($noRoles)['warnings'];
    expect($w)->toHaveCount(3)->and(implode(' ', $w))->toContain('estimated referral value')->toContain('lead temperature')->toContain('Mark it sensitive');
});

it('requires exactly one won and one lost stage, starting with an open stage', function () {
    expect(Schemas::checkStages([['key' => 'won', 'label' => 'Won', 'kind' => 'won'], ['key' => 'new', 'label' => 'New', 'kind' => 'open']])['errors'])
        ->toContain('The first stage must be an open stage (where new referrals land).')
        ->toContain('A pipeline needs exactly one Won stage and one Lost stage (they drive closing reports and the trust score).');
});

it('turns a form into validation rules for sending a referral', function () {
    $rules = Schemas::rules(FamilyDefaults::form('property'));
    $ok = Validator::make(['fields' => ['client_name' => 'Tom', 'client_phone' => '+14355550119', 'timeline' => 'Within 30 days', 'side' => 'Buying', 'price_range' => ['min' => 450000, 'max' => 520000]]], $rules);
    expect($ok->passes())->toBeTrue();
    $bad = Validator::make(['fields' => ['client_name' => 'Tom', 'timeline' => 'Someday', 'side' => 'Buying', 'price_range' => ['min' => 500000, 'max' => 100]]], $rules);
    expect($bad->errors()->keys())->toContain('fields.client_phone')->toContain('fields.timeline')->toContain('fields.price_range.max');
});

it('inherits the family default until customised, versions saves and resets', function () {
    $industry = templIndustry();
    $t = app(IndustryTemplates::class);
    expect($t->resolve('form', $industry)['source'])->toBe('family');

    $custom = FamilyDefaults::form('home');
    $custom[] = ['key' => 'roof_type', 'label' => 'Roof type', 'type' => 'choice', 'required' => false, 'sensitive' => false, 'options' => ['Shingle', 'Tile', 'Metal']];
    $this->actingAs(templAdmin())->put('/admin/industries/solar/form', ['body' => $custom])->assertSessionHas('status', 'template-saved');
    $r = $t->resolve('form', $industry);
    expect($r['source'])->toBe('industry')->and($r['version'])->toBe(1)->and(collect($r['body'])->pluck('key'))->toContain('roof_type');

    $this->delete('/admin/industries/solar/form')->assertSessionHas('status', 'template-reset');
    expect($t->resolve('form', $industry)['source'])->toBe('family')->and($t->history('form', $industry))->toHaveCount(1);
});

it('blocks invalid saves and asks to confirm warnings before saving', function () {
    templIndustry();
    $admin = templAdmin();
    $this->actingAs($admin)->put('/admin/industries/solar/stages', ['body' => [['key' => 'new', 'label' => 'New', 'kind' => 'open']]])->assertSessionHasErrors('body');

    $short = [['key' => 'new', 'label' => 'New', 'kind' => 'open'], ['key' => 'won', 'label' => 'Won', 'kind' => 'won'], ['key' => 'lost', 'label' => 'Lost', 'kind' => 'lost']];
    $this->put('/admin/industries/solar/stages', ['body' => $short])->assertSessionHas('warnings');
    $this->put('/admin/industries/solar/stages', ['body' => $short, 'acknowledge' => true])->assertSessionHas('status', 'template-saved');
});

it('stores only known field properties', function () {
    $industry = templIndustry();
    $body = FamilyDefaults::form('home');
    $body[0]['onclick'] = 'alert(1)';
    $this->actingAs(templAdmin())->put('/admin/industries/solar/form', ['body' => $body])->assertSessionHas('status', 'template-saved');
    expect(app(IndustryTemplates::class)->resolve('form', $industry)['body'][0])->not->toHaveKey('onclick');
});

it('shows the editors only to industry admins', function () {
    templIndustry();
    $this->actingAs(User::factory()->create())->get('/admin/industries/solar/form')->assertForbidden();
    $this->actingAs(templAdmin())->get('/admin/industries/solar/form')->assertOk()->assertInertia(fn ($p) => $p->component('admin/industries/FormBuilder')->where('source', 'family'));
    $this->get('/admin/industries/solar/stages')->assertOk()->assertInertia(fn ($p) => $p->component('admin/industries/Stages')->has('body', 7));
});
