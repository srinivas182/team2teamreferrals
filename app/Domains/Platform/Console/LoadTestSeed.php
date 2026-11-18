<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Members for load tests (Sprint 53b): loadtest1@loadtest.invalid … loadtestN@…, each leading a team, with a verified
 * email and phone, all with the same password. Sign-in limits are per email, so each simulated member needs its own.
 * Refuses to run in production unless --force; --clean removes them again.
 */
final class LoadTestSeed extends Command
{
    public const DOMAIN = 'loadtest.invalid';

    protected $signature = 't2t:loadtest:seed {--members=200} {--password=load-test-2026} {--clean} {--force}';

    protected $description = 'Create (or remove) members for the k6 load tests';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This is a production install. Run load tests on staging, or pass --force if you really mean it.');

            return self::FAILURE;
        }
        $ids = DB::table('users')->where('email', 'like', '%@'.self::DOMAIN)->pluck('id');
        if ($this->option('clean')) {
            DB::table('teams')->whereIn('leader_id', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
            $this->info("Removed {$ids->count()} load-test members.");

            return self::SUCCESS;
        }
        $n = max(1, min(100_000, (int) $this->option('members')));
        $hash = Hash::make((string) $this->option('password'));
        $made = 0;
        for ($i = 1; $i <= $n; $i++) {
            $email = "loadtest{$i}@".self::DOMAIN;
            if (DB::table('users')->where('email', $email)->exists()) {
                continue;
            }
            $u = User::query()->forceCreate(['first_name' => 'Load', 'last_name' => "Tester {$i}", 'email' => $email, 'password' => $hash,
                'email_verified_at' => now(), 'phone' => '+1435'.str_pad((string) (5_000_000 + $i), 7, '0', STR_PAD_LEFT), 'phone_verified_at' => now(),
                'tours_done' => json_encode(['member-home'])]);
            Team::forLeader($u)->update(['name' => "Load Test Team {$i}"]);
            $made++;
        }
        $this->info("{$made} load-test members created ({$n} in all), password: {$this->option('password')}");

        return self::SUCCESS;
    }
}
