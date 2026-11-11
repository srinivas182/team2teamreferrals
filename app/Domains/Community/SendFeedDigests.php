<?php

declare(strict_types=1);

namespace App\Domains\Community;

use Illuminate\Console\Command;

final class SendFeedDigests extends Command
{
    protected $signature = 't2t:feed:digest';

    protected $description = 'Daily digests every day, weekly digests on Mondays (each member’s choice)';

    public function handle(FeedDigest $digest): int
    {
        $digest->run();

        return self::SUCCESS;
    }
}
