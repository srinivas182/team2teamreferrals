<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token, public readonly string $invitedBy, public readonly bool $superAdmin = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = (string) data_get($notifiable, 'email');
        $url = url(route('password.reset', ['token' => $this->token, 'email' => $email], false));

        return (new MailMessage)
            ->subject($this->superAdmin ? 'You’re a Team to Team super admin' : $this->invitedBy.' invited you to the Team to Team admin')
            ->line($this->superAdmin
                ? 'You now have full access to the Team to Team admin.'
                : $this->invitedBy.' added you as staff on Team to Team.')
            ->action('Set your password', $url)
            ->line('After setting your password you’ll turn on two-step sign-in, which is required for everyone with admin access.')
            ->line('This link expires in 60 minutes. If it has expired, use “Forgot password” on the log-in page.');
    }
}
