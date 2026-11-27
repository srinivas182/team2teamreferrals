<?php

declare(strict_types=1);

namespace App\Domains\Support\Usability;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The invitation to a usability test: what it involves, how long it takes, what's recorded, and the link to start. */
final class UsabilityInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $url, public readonly int $tasks, public readonly ?string $industry) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = explode(' ', trim($this->name))[0] ?: 'there';
        $site = (string) config('seo.site_name', 'Team to Team Referrals');

        return (new MailMessage)->subject("Help us test {$site} (about 20 minutes)")
            ->greeting("Hello {$first},")
            ->line("Thank you for agreeing to try {$site} before we launch. You’ll do {$this->tasks} short tasks".($this->industry !== null ? " written for {$this->industry}" : '').', the way you would at work. It takes about 20 minutes, on a computer or a phone.')
            ->line('There are no wrong answers: if something is confusing, that’s the product’s fault, not yours, and it’s exactly what we need to find.')
            ->line('While you test, we note which pages you visit, how long things take, and where clicks or forms don’t work. We never record what you type.')
            ->action('Start the test', $this->url)
            ->line('The link works for 14 days. Reply to this email if you have any questions.');
    }
}
