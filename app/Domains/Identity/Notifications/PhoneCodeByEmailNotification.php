<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent straight away: the member is waiting on the verification screen. */
final class PhoneCodeByEmailNotification extends Notification
{
    public function __construct(public readonly string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->code.' is your Team to Team verification code')
            ->line('Your text message didn’t arrive, so here is your code by email: **'.$this->code.'**')
            ->line('It expires in 10 minutes. If you didn’t ask for it, you can ignore this email.');
    }
}
