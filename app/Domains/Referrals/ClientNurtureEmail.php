<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A gentle check-in for a client planning ahead, with a one-click way to stop these emails. */
final class ClientNurtureEmail extends Notification
{
    public function __construct(public readonly string $firstName, public readonly string $pro, public readonly string $portal, public readonly string $stop) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Still planning, {$this->firstName}?")
            ->line("{$this->pro} is here whenever you’re ready. No pressure: tell us where things stand in one tap.")
            ->action('Open your page', $this->portal)
            ->line('Don’t want these check-ins? [Stop these emails]('.$this->stop.').');
    }
}
