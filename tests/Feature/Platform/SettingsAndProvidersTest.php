<?php

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Deliveries\MessageDelivery;
use App\Domains\Platform\Deliveries\ProviderHealth;
use App\Domains\Platform\Http\Controllers\TwilioWebhookController;
use App\Domains\Platform\Providers\ApplyProviderSettings;
use App\Domains\Platform\Settings\Settings;
use App\Support\Sms\SmsSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

function settingsAdmin(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo('settings.manage');

    return $u;
}

it('encrypts secrets at rest and only shows the last four characters', function () {
    $s = app(Settings::class);
    $s->set('sms.twilio.token', 'super-secret-token-9f2a', secret: true);

    expect(DB::table('settings')->where('key', 'sms.twilio.token')->value('value'))->not->toContain('super-secret')
        ->and($s->get('sms.twilio.token'))->toBe('super-secret-token-9f2a')
        ->and($s->masked('sms.twilio.token'))->toBe('••••••••9f2a');
});

it('keeps system settings behind the settings permission', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole('staff');
    $this->actingAs($staff)->get('/admin/system')->assertForbidden();
    $this->actingAs(settingsAdmin())->get('/admin/system/email')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/system/Index')->where('tab', 'email')->has('email.drivers', 5)->where('email.health.status', 'idle'));
});

it('switches the email provider without a deploy and audits it without revealing secrets', function () {
    $admin = settingsAdmin();
    $this->actingAs($admin)->put('/admin/system/email', [
        'driver' => 'postmark', 'from_address' => 'hello@teamtoteamreferrals.com', 'from_name' => 'Team to Team',
        'fields' => ['token' => 'pm-token-1234'],
    ])->assertSessionHas('status', 'settings-saved');

    app(ApplyProviderSettings::class)();
    expect(config('mail.default'))->toBe('postmark')
        ->and(config('services.postmark.key'))->toBe('pm-token-1234')
        ->and(config('mail.from.address'))->toBe('hello@teamtoteamreferrals.com');

    $entry = Activity::where('event', 'settings-changed')->latest('id')->firstOrFail();
    expect(json_encode($entry->properties))->not->toContain('pm-token-1234')->toContain('changed (hidden)');
});

it('keeps a saved secret when the field is left blank, and requires it the first time', function () {
    $admin = settingsAdmin();
    $this->actingAs($admin)->put('/admin/system/sms', ['driver' => 'twilio', 'fields' => ['sid' => 'AC123', 'from' => '+14355550100']])
        ->assertSessionHasErrors('fields.token');

    $this->put('/admin/system/sms', ['driver' => 'twilio', 'fields' => ['sid' => 'AC123', 'token' => 'tok-abcd', 'from' => '+14355550100']])->assertSessionHasNoErrors();
    $this->put('/admin/system/sms', ['driver' => 'twilio', 'fields' => ['sid' => 'AC999', 'token' => '', 'from' => '+14355550100']])->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('sms.twilio.token'))->toBe('tok-abcd')->and(app(Settings::class)->get('sms.twilio.sid'))->toBe('AC999');
});

it('sends texts through Twilio when configured and logs each one', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
    $s = app(Settings::class);
    $s->set('sms.driver', 'twilio');
    $s->set('sms.twilio.sid', 'AC123');
    $s->set('sms.twilio.token', 'tok', secret: true);
    $s->set('sms.twilio.from', 'MG456');

    expect(app(SmsSender::class)->send('+14355550119', '123456 is your Team to Team verification code.'))->toBe('SM123');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'Accounts/AC123/Messages.json') && $r['MessagingServiceSid'] === 'MG456' && $r['To'] === '+14355550119');

    $d = MessageDelivery::firstOrFail();
    expect($d->provider)->toBe('twilio')->and($d->status)->toBe('sent')->and($d->provider_message_id)->toBe('SM123')
        ->and($d->subject)->toBe('Verification code');
});

it('logs failed texts with the provider error', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Invalid To number'], 400)]);
    $s = app(Settings::class);
    $s->set('sms.driver', 'twilio');
    $s->set('sms.twilio.sid', 'AC1');
    $s->set('sms.twilio.token', 'tok', secret: true);
    $s->set('sms.twilio.from', '+14355550100');

    expect(fn () => app(SmsSender::class)->send('+14355550119', 'hello'))->toThrow(RuntimeException::class);
    expect(MessageDelivery::firstOrFail())->status->toBe('failed')->error->toContain('Invalid To number');
});

it('updates delivery status from signed Twilio callbacks and rejects unsigned ones', function () {
    app(Settings::class)->set('sms.twilio.token', 'tok', secret: true);
    MessageDelivery::create(['channel' => 'sms', 'provider' => 'twilio', 'recipient' => '+14355550119', 'provider_message_id' => 'SM9']);
    $params = ['MessageSid' => 'SM9', 'MessageStatus' => 'delivered'];
    $url = route('webhooks.twilio.status');

    $this->post($url, $params)->assertForbidden();

    $signature = base64_encode(hash_hmac('sha1', $url.'MessageSidSM9MessageStatusdelivered', 'tok', true));
    $this->withHeader('X-Twilio-Signature', $signature)->post($url, $params)->assertNoContent();
    expect(MessageDelivery::firstOrFail()->status)->toBe('delivered');
});

it('records every email in the delivery log and sends admin test emails', function () {
    $this->actingAs(settingsAdmin())->post('/admin/system/email/test', ['to' => 'ops@example.com'])->assertSessionHas('status', 'test-email-sent');
    $d = MessageDelivery::where('channel', 'mail')->firstOrFail();
    expect($d->recipient)->toBe('ops@example.com')->and($d->subject)->toBe('Team to Team test email')->and($d->status)->toBe('sent');

    $this->get('/admin/deliveries?channel=mail')->assertOk()->assertInertia(fn ($p) => $p->component('admin/Deliveries')->has('entries', 1));
});

it('rates provider health from the last hour of sends', function () {
    expect(ProviderHealth::for('sms')['status'])->toBe('idle');
    foreach (['sent', 'sent', 'sent', 'sent', 'failed'] as $status) {
        MessageDelivery::create(['channel' => 'sms', 'provider' => 'twilio', 'recipient' => '+1', 'status' => $status]);
    }
    expect(ProviderHealth::for('sms'))->status->toBe('failing')->failed->toBe(1)->sent->toBe(5);
});

it('emails the phone code when the text does not arrive', function () {
    fakeSms();
    Illuminate\Support\Facades\Notification::fake();
    $user = User::factory()->phoneUnverified()->create();
    $this->actingAs($user)->post('/verify-phone/email')->assertSessionHas('status', 'code-emailed');

    $code = null;
    Illuminate\Support\Facades\Notification::assertSentTo($user, App\Domains\Identity\Notifications\PhoneCodeByEmailNotification::class, function ($n) use (&$code) {
        $code = $n->code;

        return true;
    });
    $this->post('/verify-phone', ['code' => $code])->assertRedirect('/app');
    expect($user->fresh()->hasVerifiedPhone())->toBeTrue();
});

it('validates Twilio signatures exactly as Twilio computes them', function () {
    $r = Request::create('https://example.com/webhooks/twilio/status', 'POST', ['b' => '2', 'a' => '1']);
    $r->headers->set('X-Twilio-Signature', base64_encode(hash_hmac('sha1', 'https://example.com/webhooks/twilio/status'.'a1b2', 'k', true)));
    expect(TwilioWebhookController::validSignature($r, 'k'))->toBeTrue()->and(TwilioWebhookController::validSignature($r, 'wrong'))->toBeFalse();
});
