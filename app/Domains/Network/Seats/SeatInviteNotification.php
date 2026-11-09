<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SeatInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $group, public readonly string $territory, public readonly string $url, public readonly string $inviter) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviter} invited you to hold {$this->territory} in {$this->group}")
            ->line("You’re invited to take the {$this->territory} seat in {$this->group} on Team to Team Referrals: one verified pro per territory, exchanging tracked referrals across the country.")
            ->action('Accept the invitation', $this->url)
            ->line('The invitation is valid for 14 days. If you don’t have an account yet, you’ll create one first.');
    }
}
