<?php

declare(strict_types=1);

namespace App\Domains\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email about a support ticket. Replies go to the ticket's own address when reply-by-email is set up. */
final class TicketMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $lines */
    public function __construct(public readonly string $subject, public readonly array $lines, public readonly string $link, public readonly ?string $replyTo = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        $mail->action('Open your ticket', $this->link);
        if ($this->replyTo !== null) {
            $mail->replyTo($this->replyTo);
        }

        return $mail;
    }
}
