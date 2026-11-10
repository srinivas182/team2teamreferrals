<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The client's one optional question after a confirmed close. */
final class ClientRatingRequest extends Notification
{
    public function __construct(public readonly string $pro, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("How was your experience with {$this->pro}?")->line('One quick, optional question: how did it go?')->action('Rate your experience', $this->link)->line('It takes ten seconds and helps other people choose well.');
    }
}
