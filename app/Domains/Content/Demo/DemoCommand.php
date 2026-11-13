<?php

declare(strict_types=1);

namespace App\Domains\Content\Demo;

use Illuminate\Console\Command;
use RuntimeException;

final class DemoCommand extends Command
{
    protected $signature = 't2t:demo {action=create : create or reset} {--force : Allow in production (only on a demo install)}';

    protected $description = 'Create or reset the demo workspace (never sends anything; refused in production unless forced)';

    public function handle(): int
    {
        if ($this->argument('action') === 'reset') {
            $this->info('Removed '.DemoWorkspace::reset().' demo records.');

            return self::SUCCESS;
        }
        try {
            $r = DemoWorkspace::create((bool) $this->option('force'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Demo workspace: {$r['teams']} teams and {$r['referrals']} referrals.");
        $this->line("Sign in as {$r['owner']} (network owner) or demo2@".DemoWorkspace::DOMAIN.' (a team), password '.DemoWorkspace::PASSWORD.'.');

        return self::SUCCESS;
    }
}
