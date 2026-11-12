<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Trust;

use Illuminate\Console\Command;

final class TrustCommand extends Command
{
    protected $signature = 't2t:trust';

    protected $description = 'Recompute every team’s trust score (nightly)';

    public function handle(): int
    {
        $this->info('Recomputed '.TrustScore::recomputeAll().' trust scores.');

        return self::SUCCESS;
    }
}
