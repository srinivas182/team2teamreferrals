<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewDeviceSignInNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $device, public readonly ?string $ip, public readonly string $when, public readonly string $secureUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your Team to Team account')
            ->line("Your account was just signed in from {$this->device}".($this->ip ? " ({$this->ip})" : '')." on {$this->when}.")
            ->line('If this was you, there’s nothing to do.')
            ->action('This wasn’t me — secure my account', $this->secureUrl)
            ->line('Securing your account signs out every device, removes saved passkeys and emails you a link to choose a new password.');
    }
}
