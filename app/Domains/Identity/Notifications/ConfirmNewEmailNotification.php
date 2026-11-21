<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the new address: the change only happens when its owner clicks. */
final class ConfirmNewEmailNotification extends Notification
{
    public function __construct(public readonly string $firstName, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new email for Team to Team Referrals')
            ->greeting('Hi '.$this->firstName.',')
            ->line('You asked to use this address to sign in to Team to Team Referrals. Confirm it and it becomes your sign-in email.')
            ->action('Confirm this email', $this->link)
            ->line('The link works for 24 hours. If you didn’t ask for this, ignore this email: nothing changes.');
    }
}
