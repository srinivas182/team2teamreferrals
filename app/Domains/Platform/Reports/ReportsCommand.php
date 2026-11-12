<?php

declare(strict_types=1);

namespace App\Domains\Platform\Reports;

use Illuminate\Console\Command;

final class ReportsCommand extends Command
{
    protected $signature = 't2t:reports';

    protected $description = 'Send scheduled reports that are due, and retry recent failures (every 15 minutes)';

    public function handle(): int
    {
        $this->info('Sent '.Schedules::runDue().' reports.');

        return self::SUCCESS;
    }
}
