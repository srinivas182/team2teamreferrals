<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email, or a bundle of several. Every email says why it was sent and links straight to that setting.
 *
 * @phpstan-type Item array{title: string, body: string, url: ?string}
 */
final class PlatformMessage extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{title: string, body: string, url: ?string}> $items */
    public function __construct(public readonly string $type, public readonly array $items, public readonly bool $digest = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $why = route('settings.notifications').'#'.str_replace('.', '-', $this->type);
        if (count($this->items) === 1) {
            $item = $this->items[0];
            $mail = (new MailMessage)->subject($item['title'])->line($item['body']);
            if ($item['url']) {
                $mail->action('Open in Team to Team', $item['url']);
            }
        } else {
            $mail = (new MailMessage)->subject(($this->digest ? 'Your daily summary: ' : '').count($this->items).' updates on your referrals');
            foreach ($this->items as $item) {
                $mail->line('**'.$item['title'].'**')->line($item['body']);
            }
            $mail->action('Open Team to Team', route('notifications.index'));
            $why = route('settings.notifications');
        }

        return $mail->line('Why am I getting this? [Change this notification]('.$why.')');
    }
}
