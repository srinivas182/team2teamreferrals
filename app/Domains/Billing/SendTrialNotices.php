<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use Illuminate\Console\Command;

final class SendTrialNotices extends Command
{
    protected $signature = 't2t:billing:trial-notices';

    protected $description = 'Tell payers 14, 7 and 1 days before each seat’s free trial ends';

    public function handle(BillingAccounts $accounts): int
    {
        $accounts->trialNotices();

        return self::SUCCESS;
    }
}
