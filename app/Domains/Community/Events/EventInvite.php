<?php

declare(strict_types=1);

namespace App\Domains\Community\Events;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The calendar invite email: "add to calendar" in one tap, updated or cancelled the same way. */
final class EventInvite extends Notification
{
    public function __construct(public readonly string $subject, public readonly string $line, public readonly string $ics, public readonly string $url, public readonly bool $cancel = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->line($this->line)->action('Open the event', $this->url)
            ->attachData($this->ics, 'event.ics', ['mime' => 'text/calendar; charset=utf-8; method='.($this->cancel ? 'CANCEL' : 'REQUEST')]);
    }
}
