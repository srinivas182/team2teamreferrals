<?php

use App\Domains\Identity\Models\User;

it('sends unverified mobiles to verification and accepts the right code', function () {
    $sms = fakeSms();
    $user = User::factory()->phoneUnverified()->create();

    $this->actingAs($user)->get('/app')->assertRedirect('/verify-phone');
    $this->post('/verify-phone/send')->assertSessionHas('status', 'code-sent');
    $this->post('/verify-phone', ['code' => $sms->lastCode()])->assertRedirect('/app');

    expect($user->fresh()->hasVerifiedPhone())->toBeTrue();
    $this->get('/app')->assertOk();
});

it('rejects wrong codes and burns the code after five attempts', function () {
    $sms = fakeSms();
    $user = User::factory()->phoneUnverified()->create();
    $this->actingAs($user)->post('/verify-phone/send');
    $right = $sms->lastCode();

    foreach (range(1, 5) as $_) {
        $this->post('/verify-phone', ['code' => '000000'])->assertSessionHasErrors('code');
    }
    $this->post('/verify-phone', ['code' => $right])->assertSessionHasErrors('code');
    expect($user->fresh()->hasVerifiedPhone())->toBeFalse();
});

it('limits how many codes can be requested', function () {
    fakeSms();
    $user = User::factory()->phoneUnverified()->create();
    $this->actingAs($user);
    foreach (range(1, 3) as $_) {
        $this->post('/verify-phone/send')->assertSessionHasNoErrors();
    }
    $this->post('/verify-phone/send')->assertSessionHasErrors('code');
});

it('lets members change the number and texts the new one', function () {
    $sms = fakeSms();
    $user = User::factory()->phoneUnverified()->create();
    $this->actingAs($user)->put('/verify-phone/number', ['phone' => '801-555-0142'])->assertSessionHasNoErrors();
    expect($user->fresh()->phone)->toBe('+18015550142')->and(end($sms->sent)['to'])->toBe('+18015550142');
});
