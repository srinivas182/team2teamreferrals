<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use Illuminate\Console\Command;

final class ExpireReferrals extends Command
{
    protected $signature = 't2t:referrals:expire';

    protected $description = 'Pass referrals past their response deadline to the backup, and remind unsigned agreements after 24 hours';

    public function handle(Referrals $referrals): int
    {
        $this->info($referrals->expireOverdue().' referral(s) moved on.');
        app(ReferralAgreements::class)->remind();

        return self::SUCCESS;
    }
}
