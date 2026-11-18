<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Go-live (Sprint 54a): an email to ALERT_EMAIL when the server's health watch finds a problem (and when it recovers).
 * Sent straight away, not queued: the queue may be what's broken. If the app itself is down this can't send; the
 * external uptime check (DigitalOcean Uptime) covers that case.
 */
final class OpsAlertCommand extends Command
{
    protected $signature = 't2t:ops:alert {title} {detail?}';

    protected $description = 'Email an operations alert to ALERT_EMAIL (used by the server health watch)';

    public function handle(): int
    {
        $to = (string) config('services.ops.alert_email');
        if ($to === '') {
            $this->warn('ALERT_EMAIL is not set; no email sent.');

            return self::SUCCESS;
        }
        $subject = '['.config('app.name').' '.app()->environment().'] '.$this->argument('title');
        $body = trim((string) $this->argument('detail'))."\n\nServer time: ".now()->toDateTimeString()." UTC\nWhat to do: docs/operations/runbooks.md\n";
        Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        $this->info("Sent to {$to}.");

        return self::SUCCESS;
    }
}
