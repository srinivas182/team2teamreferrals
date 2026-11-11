<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Confirms a non-member's referral from a public profile before the team sees it (stops spam and typos). */
final class ProfileLeadVerify extends Notification
{
    public function __construct(public readonly string $team, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Confirm your referral to {$this->team}")
            ->line("Thanks for referring a client to {$this->team}. Please confirm it’s you, and we’ll pass it on straight away.")
            ->action('Confirm my referral', $this->link)
            ->line('Didn’t send this? You can ignore this email; nothing is passed on without confirmation.');
    }
}
