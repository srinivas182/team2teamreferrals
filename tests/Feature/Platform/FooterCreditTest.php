<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole(Permissions::SUPER_ADMIN);
});

it('shows "Powered by MCS" in every footer, and only a super admin can change it', function () {
    $this->get('/pricing')->assertInertia(fn ($p) => $p->where('credit', ['text' => 'Powered by MCS', 'url' => null, 'linkText' => null]));
    $this->actingAs($this->admin)->put('/admin/system/footer-credit', ['enabled' => true, 'text' => 'Powered by MCS', 'url' => 'http://insecure.example'])->assertSessionHasErrors('url');
    $this->actingAs($this->admin)->put('/admin/system/footer-credit', ['enabled' => true, 'text' => 'Powered by MCS', 'url' => 'https://mcs.example/'])->assertSessionHas('status', 'settings-saved');
    $this->get('/pricing')->assertInertia(fn ($p) => $p->where('credit', ['text' => 'Powered by MCS', 'url' => 'https://mcs.example/', 'linkText' => null]));
    $this->actingAs($this->admin)->get('/admin/system/general')->assertInertia(fn ($p) => $p->where('footerCredit.canEdit', true)->where('footerCredit.url', 'https://mcs.example/')
        ->where('credit.text', 'Powered by MCS'));

    // Other staff see it but can't change it.
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(Permissions::STAFF);
    $staff->givePermissionTo('settings.manage');
    $this->actingAs($staff)->get('/admin/system/general')->assertInertia(fn ($p) => $p->where('footerCredit.canEdit', false));
    $this->actingAs($staff)->put('/admin/system/footer-credit', ['enabled' => false])->assertForbidden();

    // Switched off: no credit anywhere; the change is in the audit log.
    $this->actingAs($this->admin)->put('/admin/system/footer-credit', ['enabled' => false, 'text' => 'Powered by MCS', 'url' => 'https://mcs.example/']);
    $this->flushSession();
    $this->get('/pricing')->assertInertia(fn ($p) => $p->where('credit', null));
    expect(Illuminate\Support\Facades\DB::table('activity_log')->where('description', 'like', '%footer%')->exists())->toBeTrue();
});

it('can link just some words of the credit, such as the company name', function () {
    $text = 'Developed and maintained by Example Consultancy';
    $this->actingAs($this->admin)->put('/admin/system/footer-credit', ['enabled' => true, 'text' => $text, 'url' => 'https://example.com', 'link_text' => 'Not in the text'])
        ->assertSessionHasErrors('link_text');
    $this->actingAs($this->admin)->put('/admin/system/footer-credit', ['enabled' => true, 'text' => $text, 'url' => 'https://example.com', 'link_text' => ' Example Consultancy '])
        ->assertSessionHas('status', 'settings-saved');
    $this->flushSession();
    $this->get('/pricing')->assertInertia(fn ($p) => $p->where('credit', ['text' => $text, 'url' => 'https://example.com', 'linkText' => 'Example Consultancy']));
});
