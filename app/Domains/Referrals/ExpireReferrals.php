<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use Illuminate\Console\Command;

final class ExpireReferrals extends Command
{
    protected $signature = 't2t:referrals:expire';

    protected $description = 'Pass referrals past their response deadline to the backup, remind unsigned agreements, and run pipeline reminders and nudges';

    public function handle(Referrals $referrals): int
    {
        $this->info($referrals->expireOverdue().' referral(s) moved on.');
        app(ReferralAgreements::class)->remind();
        app(Pipeline::class)->tick();

        return self::SUCCESS;
    }
}
