<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Mail;

use App\Domains\Identity\Models\User;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * The mail channel, plus the recipient's full name for the email view, so every email opens with a plain
 * "Hello Priya Shah," instead of a bold "Hello!". People who aren't members (an invited email address) get "Hello,".
 */
final class PersonalMailChannel extends MailChannel
{
    private ?string $recipientName = null;

    /** @param mixed $notifiable */
    public function send($notifiable, Notification $notification)
    {
        $this->recipientName = $notifiable instanceof User ? trim((string) $notifiable->name) : null;
        try {
            return parent::send($notifiable, $notification);
        } finally {
            $this->recipientName = null;
        }
    }

    /** @return array<string, mixed> */
    protected function additionalMessageData($notification)
    {
        return [...parent::additionalMessageData($notification), 'recipientName' => $this->recipientName];
    }
}
