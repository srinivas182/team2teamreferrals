<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Content\Tokens;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/** The email itself. */
final class GrowthMessage extends Notification
{
    public function __construct(private readonly string $subject, private readonly string $text, private readonly ?string $ctaLabel, private readonly ?string $ctaUrl, private readonly string $unsubscribe) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = app(Settings::class);
        $company = Tokens::company();
        $mail = (new MailMessage)->subject($this->subject)->from((string) $s->get('growth.from_address', 'hello@news.teamtoteamreferrals.com'), (string) $s->get('growth.from_name', 'Team to Team Referrals'))
            ->greeting(' ');
        foreach (preg_split("/\n{2,}/", trim($this->text)) ?: [] as $para) {
            $mail->line($para);
        }
        if ($this->ctaLabel !== null && $this->ctaUrl !== null) {
            $mail->action($this->ctaLabel, $this->ctaUrl);
        }

        return $mail->salutation(' ')
            ->line("—\n{$company['company_legal_name']} · {$company['postal_address']}")
            ->line('You’re getting this because you showed interest in joining a Team to Team Referrals network. [Unsubscribe]('.$this->unsubscribe.')')
            ->withSymfonyMessage(function (Email $m): void {
                $m->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribe.'>');
                $m->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
