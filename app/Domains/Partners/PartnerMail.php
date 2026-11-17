<?php

declare(strict_types=1);

namespace App\Domains\Partners;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Plain transactional email to a partner or to someone who wrote to one. */
final class PartnerMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $lines */
    public function __construct(public readonly string $subject, public readonly array $lines, public readonly ?string $action = null, public readonly ?string $url = null,
        public readonly ?string $replyTo = null) {}

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
        if ($this->action !== null && $this->url !== null) {
            $mail->action($this->action, $this->url);
        }
        if ($this->replyTo !== null) {
            $mail->replyTo($this->replyTo);
        }

        return $mail;
    }
}
