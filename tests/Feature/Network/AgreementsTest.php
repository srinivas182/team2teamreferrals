<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Agreements\AgreementDefaults;
use App\Domains\Network\Industries\Agreements\AgreementRenderer;
use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Locations\Models\State;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]));

function agreementIndustry(string $tier = 'A', string $slug = 'real-estate'): Industry
{
    return Industry::create(['slug' => $slug, 'name' => 'Real estate', 'family' => 'property', 'tier' => Tier::from($tier), 'territory_unit' => 'city',
        'terminology' => ['pro' => 'agent', 'pros' => 'agents', 'client' => 'buyer', 'clients' => 'buyers']]);
}

function agreementAdmin(string $role = 'super_admin'): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole($role);
    if ($role === 'staff') {
        $u->givePermissionTo('industries.manage');
    }

    return $u;
}

it('starts each tier with the right default agreement', function () {
    expect(collect(AgreementDefaults::for(Tier::Open))->pluck('key'))->toContain('fee', 'payment_method', 'governing_law')
        ->and(collect(AgreementDefaults::for(Tier::Restricted))->pluck('key'))->toContain('no_fee', 'consent')->not->toContain('fee')
        ->and(AgreementDefaults::for(Tier::Directory))->toBe([]);
});

it('blocks fee language in restricted industries', function () {
    $ind = agreementIndustry('B');
    $svc = app(Agreements::class);
    $clauses = AgreementDefaults::for(Tier::Restricted);

    expect(fn () => $svc->save($ind, [...$clauses, ['key' => 'pay', 'title' => 'Pay', 'text' => 'Pay {fee_terms} on closing.', 'enabled' => true]], null))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(fn () => $svc->save($ind, [...$clauses, ['key' => 'pay2', 'title' => 'Pay', 'text' => 'The receiver will pay the referring party a referral fee.', 'enabled' => true]], null))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(fn () => $svc->save($ind, [...$clauses, ['key' => 'pay3', 'title' => 'Pay', 'text' => 'Something', 'fee' => true]], null))->toThrow(Illuminate\Validation\ValidationException::class);
    expect($svc->save($ind, $clauses, null))->toBe(1);
});

it('keeps required clauses and rejects unknown merge fields', function () {
    $ind = agreementIndustry();
    $svc = app(Agreements::class);
    $without = array_values(array_filter(AgreementDefaults::for(Tier::Open), fn ($c) => $c['key'] !== 'fee'));
    expect(fn () => $svc->save($ind, $without, null))->toThrow(Illuminate\Validation\ValidationException::class);
    $bad = AgreementDefaults::for(Tier::Open);
    $bad[0]['text'] .= ' {secret_field}';
    expect(fn () => $svc->save($ind, $bad, null))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('has no agreement for directory-only industries', function () {
    $ind = agreementIndustry('C');
    expect(app(Agreements::class)->forSigning($ind))->toBeNull();
    $this->actingAs(agreementAdmin())->get("/admin/industries/{$ind->slug}/agreement")->assertOk()->assertInertia(fn ($p) => $p->where('industry.tier', 'C'));
});

it('lets members sign only approved versions; drafts wait for a super admin', function () {
    $ind = agreementIndustry();
    $svc = app(Agreements::class);
    expect($svc->forSigning($ind))->toBeNull();

    $staff = agreementAdmin('staff');
    $clauses = AgreementDefaults::for(Tier::Open);
    $this->actingAs($staff)->put("/admin/industries/{$ind->slug}/agreement", ['clauses' => $clauses])->assertSessionHas('status', 'agreement-saved');
    $this->post("/admin/industries/{$ind->slug}/agreement/approve", ['counsel' => 'A. Lawyer, Firm LLP', 'note' => 'OK'])->assertForbidden();
    expect($svc->forSigning($ind))->toBeNull();

    $super = agreementAdmin();
    $this->actingAs($super)->post("/admin/industries/{$ind->slug}/agreement/approve", ['counsel' => 'A. Lawyer, Firm LLP', 'note' => 'Reviewed for TX and UT'])->assertSessionHas('status', 'agreement-approved');
    expect($svc->forSigning($ind)['version'])->toBe(1);

    $clauses[0]['text'] .= ' Updated.';
    $this->put("/admin/industries/{$ind->slug}/agreement", ['clauses' => $clauses]);
    expect($svc->current($ind)['version'])->toBe(2)->and($svc->forSigning($ind)['version'])->toBe(1);
    expect(Activity::where('event', 'agreement-approved')->exists())->toBeTrue();
});

it('approving the untouched default saves it as version 1 first', function () {
    $ind = agreementIndustry();
    expect(app(Agreements::class)->approve($ind, null, 'Counsel', 'Fine'))->toBe(1)
        ->and(app(Agreements::class)->forSigning($ind)['version'])->toBe(1);
});

it('saves and removes state addenda and adds them to the rendered agreement', function () {
    $ind = agreementIndustry();
    $tx = State::where('code', 'TX')->firstOrFail();
    $this->actingAs(agreementAdmin())->put("/admin/industries/{$ind->slug}/agreement/addenda/{$tx->id}", ['text' => 'Texas: include brokerage name and license {receiver_license}.'])->assertSessionHas('status', 'addendum-saved');
    $this->get("/admin/industries/{$ind->slug}/agreement")->assertInertia(fn ($p) => $p->has('addenda', 1)->where('addenda.0.code', 'TX'));

    $sections = AgreementRenderer::render(AgreementDefaults::for(Tier::Open), AgreementDefaults::sample(), [['state' => 'Texas', 'text' => 'license {receiver_license}']]);
    expect(end($sections))->toBe(['title' => 'State requirements: Texas', 'text' => 'license TX 0654321']);

    $this->put("/admin/industries/{$ind->slug}/agreement/addenda/{$tx->id}", ['text' => '']);
    $this->get("/admin/industries/{$ind->slug}/agreement")->assertInertia(fn ($p) => $p->has('addenda', 0));
});

it('explains the key terms in plain English', function () {
    $lines = AgreementRenderer::summary(AgreementDefaults::for(Tier::Open), AgreementDefaults::sample());
    expect(implode(' ', $lines))->toContain('Bayou City Homes pays Red Rock Realty Group 25% of the commission received, within 10 days of closing')
        ->toContain('Team to Team never collects or holds it');
    expect(implode(' ', AgreementRenderer::summary(AgreementDefaults::for(Tier::Restricted), AgreementDefaults::sample())))->toContain('No money changes hands');
});

it('quotes edited fee wording in the summary instead of paraphrasing it', function () {
    $c = AgreementDefaults::for(Tier::Open);
    $c[2]['text'] = str_replace('{fee_due_days} days', '{fee_due_days} business days', $c[2]['text']);
    expect(implode(' ', AgreementRenderer::summary($c, AgreementDefaults::sample())))->toContain('Fee terms:')->toContain('10 business days of closing');
});

it('compares versions word by word', function () {
    $a = AgreementDefaults::for(Tier::Open);
    $b = $a;
    $b[2]['text'] = str_replace('within {fee_due_days} days', 'within 30 days', $b[2]['text']);
    $b[] = ['key' => 'extra', 'title' => 'Extra', 'text' => 'New text', 'required' => false, 'optional' => true, 'enabled' => true, 'fee' => false];
    $diff = AgreementRenderer::compare($a, $b);
    expect(collect($diff)->pluck('status', 'key')->all())->toBe(['fee' => 'changed', 'extra' => 'added']);
    $fee = collect($diff)->firstWhere('key', 'fee');
    expect(collect($fee['parts'])->where(0, '+')->pluck(1)->implode(''))->toContain('30')
        ->and(collect($fee['parts'])->where(0, '-')->pluck(1)->implode(''))->toContain('{fee_due_days}');
});

it('renders the PDF preview and the counsel review pack', function () {
    $ind = agreementIndustry();
    $this->actingAs(agreementAdmin())->get("/admin/industries/{$ind->slug}/agreement/preview.pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    $res = $this->get("/admin/industries/{$ind->slug}/agreement/counsel-pack.pdf")->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('real-estate-counsel-review-pack.pdf');
    expect(Activity::where('event', 'counsel-pack')->exists())->toBeTrue();
});

it('keeps the agreement screens behind the industries permission', function () {
    $ind = agreementIndustry();
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole('staff');
    $this->actingAs($staff)->get("/admin/industries/{$ind->slug}/agreement")->assertForbidden();
});
