<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use Illuminate\Notifications\Notification;

/** The bell and the notifications page. Stored immediately, never delayed by quiet hours. */
final class InAppNotification extends Notification
{
    public function __construct(public readonly string $type, public readonly string $title, public readonly string $body, public readonly ?string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /** @return array{title: string, body: string, url: ?string} */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
