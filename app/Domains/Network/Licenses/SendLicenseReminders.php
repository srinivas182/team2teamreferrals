<?php

declare(strict_types=1);

namespace App\Domains\Network\Licenses;

use Illuminate\Console\Command;

final class SendLicenseReminders extends Command
{
    protected $signature = 't2t:licenses:reminders';

    protected $description = 'Remind teams 30 and 7 days before a license expires, and mark expired licenses';

    public function handle(Licenses $licenses): int
    {
        $licenses->remind();

        return self::SUCCESS;
    }
}
