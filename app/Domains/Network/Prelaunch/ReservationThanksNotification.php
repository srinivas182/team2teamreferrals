<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "You're a founding member": the share link, a ready caption, and the card attached for forwarding to clients. */
final class ReservationThanksNotification extends Notification
{
    public function __construct(public readonly string $territory, public readonly string $group, public readonly string $shareUrl, public readonly ?string $cardPath) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("You’re a founding member: {$this->territory}")
            ->line("{$this->territory} is reserved for you in {$this->group}.")
            ->line('Share the news with clients and peers. Every pro who reserves through your link and gets verified moves you up waitlists.')
            ->line("Suggested post: “We’ve reserved {$this->territory} on Team to Team Referrals. If you have a client moving here, we’re the verified pro to call.”")
            ->action('Your share page', $this->shareUrl);
        if ($this->cardPath !== null && is_file($this->cardPath)) {
            $mail->attach($this->cardPath, ['as' => 'we-claimed-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($this->territory)).'.png', 'mime' => 'image/png']);
        }

        return $mail;
    }
}
