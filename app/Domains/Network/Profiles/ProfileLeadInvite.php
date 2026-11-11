<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** After a non-member's referral is confirmed: thanks, and an invitation to join through that team's link. */
final class ProfileLeadInvite extends Notification
{
    public function __construct(public readonly string $team, public readonly string $join) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->team} has your referral")
            ->line("Thank you for referring a client to {$this->team}. They’ll be in touch with them soon.")
            ->line('Want referrals sent your way too? Team to Team Referrals connects one verified pro per city, per industry, and tracks every referral.')
            ->action('Join Team to Team Referrals', $this->join);
    }
}
