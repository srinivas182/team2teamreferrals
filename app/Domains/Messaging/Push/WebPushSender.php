<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Push;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\PushSubscription;
use App\Domains\Platform\Settings\Settings;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/** Standard Web Push (VAPID): no third-party push service. Expired browser subscriptions are removed. */
final class WebPushSender implements PushSender
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array{publicKey: string, privateKey: string} */
    public function keys(): array
    {
        if (! $this->settings->has('push.vapid_public') || ! $this->settings->has('push.vapid_private')) {
            $keys = VAPID::createVapidKeys();
            $this->settings->set('push.vapid_public', $keys['publicKey']);
            $this->settings->set('push.vapid_private', $keys['privateKey'], secret: true);
        }

        return ['publicKey' => (string) $this->settings->get('push.vapid_public'), 'privateKey' => (string) $this->settings->get('push.vapid_private')];
    }

    public function send(User $user, string $title, string $body, ?string $url): int
    {
        $subs = PushSubscription::query()->where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        $keys = $this->keys();
        $push = new WebPush(['VAPID' => ['subject' => (string) config('app.url'), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]]);
        $payload = (string) json_encode(['title' => $title, 'body' => $body, 'url' => $url]);
        foreach ($subs as $s) {
            $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token]), $payload);
        }
        $ok = 0;
        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                $ok++;
            } elseif ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
            }
        }

        return $ok;
    }
}
