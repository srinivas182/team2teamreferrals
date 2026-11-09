<?php

declare(strict_types=1);

namespace App\Domains\Network\Teams;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TeamInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $team, public readonly string $role, public readonly string $url, public readonly string $inviter) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->inviter} invited you to join {$this->team} on Team to Team")
            ->line("You’re invited to join {$this->team} as a {$this->role}.")
            ->action('Join the team', $this->url)
            ->line('The invitation is valid for 14 days.');
    }
}
