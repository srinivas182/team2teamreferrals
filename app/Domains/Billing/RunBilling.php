<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use Illuminate\Console\Command;

/** On the 1st: invoice and charge last month. Daily: retry failed payments that are due. */
final class RunBilling extends Command
{
    protected $signature = 't2t:billing:run {--period= : Bill this month (YYYY-MM) instead of last month} {--retries : Only retry failed payments}';

    protected $description = 'Monthly invoices and charges (on the 1st) and payment retries (daily)';

    public function handle(Invoicing $invoicing): int
    {
        $invoicing->retryDue();
        if ($this->option('retries')) {
            return self::SUCCESS;
        }
        $period = (string) ($this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m'));
        $this->info($invoicing->run($period)." invoice(s) for {$period}.");

        return self::SUCCESS;
    }
}
