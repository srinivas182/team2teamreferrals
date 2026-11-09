<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats;

use Illuminate\Console\Command;

final class RunSeatLifecycle extends Command
{
    protected $signature = 't2t:seats:lifecycle';

    protected $description = 'Move seats from grace to inactive to released, and pass expired seat offers to the next team';

    public function handle(Seats $seats): int
    {
        $seats->tick();

        return self::SUCCESS;
    }
}
