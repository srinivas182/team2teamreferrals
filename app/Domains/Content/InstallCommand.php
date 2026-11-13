<?php

declare(strict_types=1);

namespace App\Domains\Content;

use Illuminate\Console\Command;

/** Set up a fresh install: database, industry catalogue, US locations and the content pack. */
final class InstallCommand extends Command
{
    protected $signature = 't2t:install {--locations= : Folder with states.csv, counties.csv and cities.csv(.gz); defaults to the bundled US data} {--demo : Also create the demo workspace} {--force : With --demo, allow it in production (demo installs only)}';

    protected $description = 'Set up a fresh install in one step (safe to run again)';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('t2t:catalogue:sync');
        $this->call('t2t:locations:import', array_filter(['--path' => $this->option('locations') ?: database_path('data/us')]));
        $this->info('Content pack: '.ContentPack::install().' pages published.');
        if ($this->option('demo') && ! Demo\DemoWorkspace::exists()) {
            $this->call('t2t:demo', array_filter(['--force' => (bool) $this->option('force')]));
        }
        $this->info('Done. The public site is ready.');

        return self::SUCCESS;
    }
}
