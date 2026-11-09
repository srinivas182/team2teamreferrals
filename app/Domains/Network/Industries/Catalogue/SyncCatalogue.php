<?php

declare(strict_types=1);

namespace App\Domains\Network\Industries\Catalogue;

use Illuminate\Console\Command;

final class SyncCatalogue extends Command
{
    protected $signature = 't2t:catalogue:sync';

    protected $description = 'Create or refresh the 46 launch industries without overwriting admin changes';

    public function handle(CatalogueSync $sync): int
    {
        $s = $sync->run();
        $this->info("Launch catalogue: {$s['created']} created, {$s['updated']} updated, {$s['kept']} admin changes kept.");

        return self::SUCCESS;
    }
}
