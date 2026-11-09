<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Mail\PlatformMessage;
use App\Domains\Messaging\Models\OutboxItem;
use App\Domains\Messaging\Push\PushSender;
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
    public function send(User $user, string $type, array $data = [], ?string $url = null): void
    {
        $def = NotificationCatalogue::get($type);
        $data = ['first_name' => $user->first_name, 'url' => $url, ...$data];
        $now = CarbonImmutable::now();
        $quietUntil = $def['urgent'] ? null : Preferences::quietUntil($user, $now);

        foreach ($def['channels'] as $channel) {
            if (! $this->prefs->allows($user, $type, $channel)) {
                continue;
            }
            $w = $this->templates->wording($type, $channel);
            $title = NotificationCatalogue::render($w['subject'], $data);
            $body = NotificationCatalogue::render($w['body'], $data);

            match ($channel) {
                'database' => $user->notify(new InAppNotification($type, $title, $body, $url)),
                'push' => $quietUntil === null && app(\App\Domains\Platform\Modules\Modules::class)->enabled('push') ? $this->safely(fn () => $this->push->send($user, $title, $body, $url)) : null,
                'mail' => $this->mail($user, $type, $title, $body, $url, $def['urgent'], $quietUntil, $now),
                'sms' => $this->text($user, $type, $title, $body, $url, $quietUntil),
                default => null,
            };
        }
    }

    private function mail(User $user, string $type, string $title, string $body, ?string $url, bool $urgent, ?CarbonImmutable $quietUntil, CarbonImmutable $now): void
    {
        if ($urgent) {
            $user->notify(new PlatformMessage($type, [['title' => $title, 'body' => $body, 'url' => $url]]));

            return;
        }
        $after = $user->email_digest ? Preferences::nextDigest($user, $now) : ($quietUntil ?? $now->addMinutes(self::BUNDLE_MINUTES));
        OutboxItem::create(['user_id' => $user->id, 'type' => $type, 'channel' => 'mail', 'title' => $title, 'body' => $body, 'url' => $url, 'deliver_after' => $after]);
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
