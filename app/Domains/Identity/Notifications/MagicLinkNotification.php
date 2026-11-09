<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MagicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Team to Team sign-in link')
            ->line('Tap the button to sign in. The link works once and expires in '.$this->minutes.' minutes.')
            ->action('Sign in to Team to Team', $this->url)
            ->line('If you didn’t ask for this, you can ignore this email. Nobody can sign in without the link.');
    }
}
