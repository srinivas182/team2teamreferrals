<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Double opt-in: a reservation only counts once the email link is clicked. */
final class ConfirmReservationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $territory, public readonly string $group, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Confirm your reservation: {$this->territory}")
            ->line("You’re one click from reserving {$this->territory} in {$this->group} as a founding member.")
            ->action('Confirm my reservation', $this->url)
            ->line('No card needed. Founding members keep their price for as long as they hold the seat.');
    }
}
