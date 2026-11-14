<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Mail\PlatformMessage;
use App\Domains\Messaging\Models\OutboxItem;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Industries\Models\Industry;
use App\Support\Sms\SmsSender;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The one way the platform notifies members. For each channel it applies the member's
 * preferences, quiet hours (urgent types ignore them), bundling (non-urgent email waits
 * 10 minutes so updates arrive together) and the optional daily digest.
 */
final class Notifier
{
    public const BUNDLE_MINUTES = 10;

    public function __construct(
        private readonly Preferences $prefs,
        private readonly Templates $templates,
        private readonly SmsSender $sms,
        private readonly PushSender $push,
    ) {}

    /** @param array<string, scalar|null> $data */
    public const DEFAULT_TERMS = ['pro' => 'pro', 'pros' => 'pros', 'client_term' => 'client', 'clients_term' => 'clients', 'referral_term' => 'referral', 'fee_term' => 'referral fee'];

    /** @param array<string, scalar|null> $data */
    public function send(User $user, string $type, array $data = [], ?string $url = null, ?Industry $industry = null, ?int $groupId = null): void
    {
        $def = NotificationCatalogue::get($type);
        $data = [...self::DEFAULT_TERMS, ...($industry?->mergeTerms() ?? []), 'first_name' => $user->first_name, 'url' => $url, ...$data];
        $now = CarbonImmutable::now();
        $quietUntil = $def['urgent'] ? null : Preferences::quietUntil($user, $now);

        // A reply-by-email address travels with the email only; it's never shown in other channels.
        $replyTo = isset($data['_reply_to']) && is_string($data['_reply_to']) ? $data['_reply_to'] : null;
        unset($data['_reply_to']);
        // Demo accounts (…@demo.invalid, a domain that can never receive mail) get in-app notifications only.
        // So does any .invalid address (the platform's own website team, for example).
        $demo = str_ends_with(strtolower((string) $user->email), '@'.\App\Domains\Content\Demo\DemoWorkspace::DOMAIN) || str_ends_with(strtolower((string) $user->email), '.invalid');
        foreach ($def['channels'] as $channel) {
            if (! $this->prefs->allows($user, $type, $channel) || ($demo && $channel !== 'database')) {
                continue;
            }
            $w = $this->templates->wording($type, $channel, $industry?->id, $groupId);
            $title = NotificationCatalogue::render($w['subject'], $data);
            $body = NotificationCatalogue::render($w['body'], $data);

            match ($channel) {
                'database' => $user->notify(new InAppNotification($type, $title, $body, $url)),
                'push' => $quietUntil === null && app(\App\Domains\Platform\Modules\Modules::class)->enabled('push') ? $this->safely(fn () => $this->push->send($user, $title, $body, $url)) : null,
                'mail' => $this->mail($user, $type, $title, $body, $url, $def['urgent'], $quietUntil, $now, $replyTo),
                'sms' => $this->text($user, $type, $title, $body, $url, $quietUntil),
                default => null,
            };
        }
        // Status replies by WhatsApp or SMS, for members who chose them (Portal → Settings → Notifications).
        if (isset(\App\Domains\Referrals\Bot\ChatStatus::TYPES[$type]) && $quietUntil === null) {
            $this->safely(fn () => app(\App\Domains\Referrals\Bot\ChatStatus::class)->send($user, $type, $data));
        }
    }

    private function mail(User $user, string $type, string $title, string $body, ?string $url, bool $urgent, ?CarbonImmutable $quietUntil, CarbonImmutable $now, ?string $replyTo = null): void
    {
        if ($urgent) {
            $user->notify(new PlatformMessage($type, [['title' => $title, 'body' => $body, 'url' => $url, 'reply_to' => $replyTo]]));

            return;
        }
        $after = $user->email_digest ? Preferences::nextDigest($user, $now) : ($quietUntil ?? $now->addMinutes(self::BUNDLE_MINUTES));
        OutboxItem::create(['user_id' => $user->id, 'type' => $type, 'channel' => 'mail', 'title' => $title, 'body' => $body, 'url' => $url, 'reply_to' => $replyTo, 'deliver_after' => $after]);
    }

    private function text(User $user, string $type, string $title, string $body, ?string $url, ?CarbonImmutable $quietUntil): void
    {
        if ($user->phone === null || $user->phone_verified_at === null || $body === '') {
            return;
        }
        if ($quietUntil !== null) {
            OutboxItem::create(['user_id' => $user->id, 'type' => $type, 'channel' => 'sms', 'title' => $title, 'body' => $body, 'url' => $url, 'deliver_after' => $quietUntil]);

            return;
        }
        $this->safely(fn () => $this->sms->send($user->phone, $body));
    }

    /** A failing provider must never stop the other channels; the delivery log records the failure. */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
