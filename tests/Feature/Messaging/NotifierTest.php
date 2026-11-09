<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Mail\PlatformMessage;
use App\Domains\Messaging\Models\OutboxItem;
use App\Domains\Messaging\Notifier;
use App\Domains\Messaging\Preferences;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Messaging\Templates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

final class FakePush implements PushSender
{
    /** @var list<string> */
    public array $sent = [];

    public function send(User $user, string $title, string $body, ?string $url): int
    {
        $this->sent[] = $title;

        return 1;
    }
}

beforeEach(function () {
    $this->sms = fakeSms();
    $this->push = new FakePush;
    app()->instance(PushSender::class, $this->push);
    Notification::fake();
});

$data = ['client' => 'Tom Brennan', 'city' => 'Houston', 'team' => 'Bayou City Homes', 'industry' => 'real estate', 'hours' => 24];

it('sends an urgent referral on every channel at once, with merge fields filled', function () use ($data) {
    $user = User::factory()->create();
    app(Notifier::class)->send($user, 'referral.waiting', $data, url('/app/inbox'));

    Notification::assertSentTo($user, PlatformMessage::class, fn ($n) => $n->items[0]['title'] === 'New referral: Tom Brennan in Houston');
    expect($this->sms->sent)->toHaveCount(1)->and($this->sms->sent[0]['message'])->toContain('Tom Brennan in Houston')
        ->and($this->push->sent)->toBe(['New referral: Tom Brennan in Houston'])
        ->and(OutboxItem::count())->toBe(0);
});

it('respects channel preferences but never lets locked types be turned off', function () use ($data) {
    $user = User::factory()->create();
    $prefs = app(Preferences::class);
    $prefs->set($user, 'referral.waiting', 'sms', false);
    $prefs->set($user, 'billing.payment_failed', 'sms', false); // locked: ignored

    app(Notifier::class)->send($user, 'referral.waiting', $data);
    expect($this->sms->sent)->toHaveCount(0);

    app(Notifier::class)->send($user, 'billing.payment_failed', ['url' => '/app/billing']);
    expect($this->sms->sent)->toHaveCount(1);
});

it('holds non-urgent texts and push during quiet hours but lets urgent ones through', function () use ($data) {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 23:30', 'America/Denver'));
    $user = User::factory()->create(['quiet_hours_start' => '21:00', 'quiet_hours_end' => '07:00', 'timezone' => 'America/Denver']);

    app(Notifier::class)->send($user, 'seat.offered', ['city' => 'Austin', 'industry' => 'solar', 'hours' => 72]); // urgent
    expect($this->sms->sent)->toHaveCount(1);

    app(Notifier::class)->send($user, 'referral.accepted', $data); // not urgent: push held, email waits for 07:00
    expect($this->push->sent)->toHaveCount(0)
        ->and(OutboxItem::firstOrFail()->deliver_after->setTimezone('America/Denver')->format('Y-m-d H:i'))->toBe('2026-11-11 07:00');
});

it('works out overnight and same-day quiet hours in the member’s time zone', function () {
    $u = User::factory()->make(['quiet_hours_start' => '21:00', 'quiet_hours_end' => '07:00', 'timezone' => 'America/New_York']);
    expect(Preferences::quietUntil($u, CarbonImmutable::parse('2026-11-10 06:30', 'America/New_York')))->not->toBeNull()
        ->and(Preferences::quietUntil($u, CarbonImmutable::parse('2026-11-10 12:00', 'America/New_York')))->toBeNull()
        ->and(Preferences::quietUntil($u, CarbonImmutable::parse('2026-11-10 21:00', 'America/New_York'))?->setTimezone('America/New_York')->format('d H:i'))->toBe('11 07:00');
    $lunch = User::factory()->make(['quiet_hours_start' => '12:00', 'quiet_hours_end' => '13:00', 'timezone' => 'UTC']);
    expect(Preferences::quietUntil($lunch, CarbonImmutable::parse('2026-11-10 12:30', 'UTC')))->not->toBeNull()
        ->and(Preferences::quietUntil($lunch, CarbonImmutable::parse('2026-11-10 13:00', 'UTC')))->toBeNull();
});

it('bundles non-urgent emails that arrive close together into one', function () use ($data) {
    $user = User::factory()->create();
    foreach (['Tom', 'Ana', 'Lee'] as $client) {
        app(Notifier::class)->send($user, 'referral.update', [...$data, 'client' => $client]);
    }
    Notification::assertNotSentTo($user, PlatformMessage::class);
    expect(OutboxItem::count())->toBe(3);

    $this->travel(11)->minutes();
    Artisan::call('notifications:flush');
    Notification::assertSentToTimes($user, PlatformMessage::class, 1);
    Notification::assertSentTo($user, PlatformMessage::class, fn ($n) => count($n->items) === 3 && str_contains($n->toMail($user)->subject, '3 updates'));
    expect(OutboxItem::whereNull('sent_at')->count())->toBe(0);
});

it('sends daily-digest members one summary at 7:00 in their time zone', function () use ($data) {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00', 'America/Chicago'));
    $user = User::factory()->create(['email_digest' => true, 'timezone' => 'America/Chicago']);
    app(Notifier::class)->send($user, 'referral.accepted', $data);
    expect(OutboxItem::firstOrFail()->deliver_after->setTimezone('America/Chicago')->format('Y-m-d H:i'))->toBe('2026-11-11 07:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-11 07:01', 'America/Chicago'));
    Artisan::call('notifications:flush');
    Notification::assertSentTo($user, PlatformMessage::class, fn ($n) => $n->digest === true);
});

it('stores in-app notifications for the bell and marks them read', function () use ($data) {
    Notification::swap(new Illuminate\Notifications\ChannelManager(app())); // real database channel
    $user = User::factory()->create();
    app(Notifier::class)->send($user, 'referral.declined', $data);

    $this->actingAs($user)->get('/app/notifications')->assertOk()
        ->assertInertia(fn ($p) => $p->component('app/Notifications')->has('items', 1)->where('notifications.unread', 1)->where('items.0.title', 'Bayou City Homes declined Tom Brennan'));
    $id = $user->notifications()->first()->id;
    $this->post("/app/notifications/{$id}/read")->assertRedirect();
    expect($user->unreadNotifications()->count())->toBe(0);
});

it('uses the active admin template, and restores an older version', function () use ($data) {
    $user = User::factory()->create();
    $t = app(Templates::class);
    $v1 = $t->save('referral.waiting', 'sms', null, 'v1: {client} in {city}', null);
    $t->save('referral.waiting', 'sms', null, 'v2: {client} needs you in {city}', null);

    app(Notifier::class)->send($user, 'referral.waiting', $data);
    expect(end($this->sms->sent)['message'])->toBe('v2: Tom Brennan needs you in Houston');

    $t->activate($v1);
    app(Notifier::class)->send($user, 'referral.waiting', $data);
    expect(end($this->sms->sent)['message'])->toBe('v1: Tom Brennan in Houston');
});

it('puts a "why am I getting this" link in every email', function () {
    $user = User::factory()->create();
    $mail = (new PlatformMessage('referral.accepted', [['title' => 'T', 'body' => 'B', 'url' => null]]))->toMail($user);
    expect(implode(' ', [...$mail->introLines, ...$mail->outroLines]))->toContain('Why am I getting this?')->toContain('/app/settings/notifications#referral-accepted');
});
