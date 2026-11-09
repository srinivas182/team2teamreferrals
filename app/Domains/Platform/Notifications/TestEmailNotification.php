<?php

declare(strict_types=1);

namespace App\Domains\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent immediately (not queued) so the admin sees success or the provider's error on the spot. */
final class TestEmailNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Team to Team test email')
            ->line('Your email provider is working. Members will receive verification links, referral alerts and receipts from this address.');
    }
}
