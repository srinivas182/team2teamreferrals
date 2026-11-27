<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the old address after a change, so someone else changing it doesn't go unnoticed. */
final class EmailChangedNotification extends Notification
{
    public function __construct(public readonly string $firstName, public readonly string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Team to Team sign-in email was changed')
            ->greeting('Hello '.$this->firstName.',')
            ->line('Your account now signs in with '.$this->newEmail.'. This address won’t get account emails any more.')
            ->line('If you didn’t make this change, reply to this email or contact support straight away so we can secure your account.');
    }
}
