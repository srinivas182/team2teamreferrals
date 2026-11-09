<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\NotificationTemplate;
use App\Domains\Messaging\Models\PushSubscription;
use App\Domains\Platform\Deliveries\MessageDelivery;
use App\Domains\Platform\Deliveries\SmsOptOut;
use App\Domains\Platform\Settings\Settings;
use App\Support\Sms\SmsSender;

function twilioPost($test, string $from, string $body)
{
    app(Settings::class)->set('sms.twilio.token', 'tok', secret: true);
    $url = route('webhooks.twilio.inbound');
    $params = ['Body' => $body, 'From' => $from];
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }

    return $test->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $data, 'tok', true)))->post($url, $params);
}

it('opts a number out on STOP, suppresses texts, and opts back in on START', function () {
    twilioPost($this, '+14355550119', 'stop')->assertOk();
    expect(SmsOptOut::blocks('+14355550119'))->toBeTrue();

    expect(app(SmsSender::class)->send('+14355550119', 'hello'))->toBeNull();
    expect(MessageDelivery::firstOrFail()->status)->toBe('suppressed');

    twilioPost($this, '+14355550119', 'START')->assertOk();
    expect(SmsOptOut::blocks('+14355550119'))->toBeFalse();
});

it('answers HELP and rejects unsigned replies', function () {
    twilioPost($this, '+14355550119', 'help')->assertOk()->assertSee('Reply STOP to opt out', false);
    $this->post(route('webhooks.twilio.inbound'), ['Body' => 'STOP', 'From' => '+14355550119'])->assertForbidden();
});

it('saves preferences, quiet hours and the daily digest', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/app/settings/notifications')->assertOk()
        ->assertInertia(fn ($p) => $p->component('app/settings/Notifications')->where('matrix', fn ($m) => $m['referral.waiting']['sms'] === true && $m['billing.trial_ending']['mail'] === true));

    $this->put('/app/settings/notifications', [
        'matrix' => ['referral.update' => ['mail' => false], 'billing.trial_ending' => ['mail' => false]],
        'quiet_start' => '21:00', 'quiet_end' => '07:00', 'digest' => true,
    ])->assertSessionHas('status', 'notification-settings-saved');

    $user->refresh();
    expect($user->quiet_hours_start)->toStartWith('21:00')->and($user->email_digest)->toBeTrue();
    $this->get('/app/settings/notifications')->assertInertia(fn ($p) => $p->where('matrix', fn ($m) => $m['referral.update']['mail'] === false && $m['billing.trial_ending']['mail'] === true));
});

it('rejects quiet hours that start and end at the same time', function () {
    $this->actingAs(User::factory()->create())->put('/app/settings/notifications', ['quiet_start' => '09:00', 'quiet_end' => '09:00', 'digest' => false])->assertSessionHasErrors('quiet_end');
});

it('stores browser push subscriptions for the signed-in member only', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/app/push-subscriptions', ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'pk', 'auth' => 'ak']])->assertNoContent();
    $this->postJson('/app/push-subscriptions', ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'pk2', 'auth' => 'ak2']])->assertNoContent();
    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::first()->public_key)->toBe('pk2');
    $this->postJson('/app/push-subscriptions', ['endpoint' => 'http://insecure.example.com', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])->assertUnprocessable();
});

it('lets content admins edit, version and restore templates, and keeps others out', function () {
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('staff');
    $member = User::factory()->create();
    $this->actingAs($member)->get('/admin/templates')->assertForbidden();

    $admin->givePermissionTo('content.manage');
    $this->actingAs($admin)->get('/admin/templates/referral.waiting')->assertOk()->assertInertia(fn ($p) => $p->component('admin/templates/Edit')->has('channels', 3));
    $this->post('/admin/templates/referral.waiting', ['channel' => 'sms', 'body' => str_repeat('x', 321)])->assertSessionHasErrors('body');
    $this->post('/admin/templates/referral.waiting', ['channel' => 'sms', 'body' => 'New referral {client}'])->assertSessionHas('status', 'template-saved');
    $this->post('/admin/templates/referral.waiting', ['channel' => 'sms', 'body' => 'Second {client}'])->assertSessionHas('status', 'template-saved');

    $v1 = NotificationTemplate::where('version', 1)->firstOrFail();
    $this->post("/admin/templates/versions/{$v1->id}/activate")->assertSessionHas('status', 'template-restored');
    expect($v1->fresh()->is_active)->toBeTrue()->and(NotificationTemplate::where('is_active', true)->count())->toBe(1);
});
