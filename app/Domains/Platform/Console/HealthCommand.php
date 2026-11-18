<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Health\HealthChecks;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Go-live (Sprint 54a): one command that says whether the whole deployment works, not just the web page. The deploy
 * runs it after switching to a new release (and rolls back if it fails), and the server runs it every five minutes
 * so a stopped queue, scheduler or virus scanner is noticed even when the site itself still loads.
 *
 * Exit code 0 when everything required is working, 1 otherwise. --json prints the result for scripts.
 */
final class HealthCommand extends Command
{
    /** Set every minute by the scheduler; older than this means the scheduler has stopped. */
    public const HEARTBEAT = 'health:scheduler-beat';

    public const HEARTBEAT_MAX_AGE = 180;

    protected $signature = 't2t:health {--json : Print the result as JSON} {--skip=* : Checks to leave out (scheduler, workers, ssr, scanner, migrations, disk, backup)}';

    protected $description = 'Check that the deployment works: database, cache, queues and workers, scheduler, search, SSR, virus scanning, migrations and disk';

    public function handle(HealthChecks $checks, Migrator $migrator): int
    {
        $skip = array_map('strval', (array) $this->option('skip'));
        $results = [];
        foreach ($checks->run() as $name => $r) {
            $results[$name] = ['ok' => $r['ok'], 'detail' => $r['ok'] ? "{$r['ms']} ms" : 'failing'];
        }
        $extra = [
            'migrations' => fn () => $this->migrations($migrator),
            'workers' => fn () => $this->workers(),
            'scheduler' => fn () => $this->scheduler(),
            'ssr' => fn () => $this->ssr(),
            'scanner' => fn () => $this->scanner(),
            'disk' => fn () => $this->disk(),
            'backup' => fn () => $this->backup(),
        ];
        foreach ($extra as $name => $check) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            try {
                $results[$name] = $check();
            } catch (Throwable $e) {
                $results[$name] = ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
            }
        }
        $ok = ! in_array(false, array_column($results, 'ok'), true);

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $ok, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'OK', 'Detail'], array_map(fn ($n, $r) => [$n, $r['ok'] === null ? '–' : ($r['ok'] ? 'yes' : 'NO'), $r['detail']], array_keys($results), $results));
            $ok ? $this->info('Healthy.') : $this->error('Not healthy.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** Record that the scheduler is running (scheduled every minute). */
    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT, now()->getTimestamp());
    }

    /** @return array{ok: bool, detail: string} */
    private function migrations(Migrator $migrator): array
    {
        $repo = $migrator->getRepository();
        if (! $repo->repositoryExists()) {
            return ['ok' => false, 'detail' => 'The migrations table is missing.'];
        }
        $ran = $repo->getRan();
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
        $pending = array_values(array_diff($files, $ran));

        return $pending === [] ? ['ok' => true, 'detail' => count($ran).' applied'] : ['ok' => false, 'detail' => count($pending).' pending, first '.$pending[0]];
    }

    /**
     * Horizon's master supervisor is running (the queue workers). Not checked where the queue runs in-process.
     *
     * @return array{ok: bool|null, detail: string}
     */
    private function workers(): array
    {
        if (config('queue.default') !== 'redis') {
            return ['ok' => null, 'detail' => 'Queue is '.config('queue.default').'; no workers to check.'];
        }
        $masters = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
        $running = array_filter($masters, fn ($m) => ($m->status ?? '') === 'running');

        return $running !== [] ? ['ok' => true, 'detail' => count($running).' running'] : ['ok' => false, 'detail' => $masters === [] ? 'Horizon is not running.' : 'Horizon is paused.'];
    }

    /** @return array{ok: bool, detail: string} */
    private function scheduler(): array
    {
        $at = Cache::get(self::HEARTBEAT);
        // Redis gives numbers back as strings.
        if (! is_numeric($at)) {
            return ['ok' => false, 'detail' => 'No heartbeat yet.'];
        }
        $age = now()->getTimestamp() - (int) $at;

        return ['ok' => $age <= self::HEARTBEAT_MAX_AGE, 'detail' => "last ran {$age} s ago"];
    }

    /** @return array{ok: bool|null, detail: string} */
    private function ssr(): array
    {
        if (! (bool) config('inertia.ssr.enabled', false) || app()->environment('local', 'testing')) {
            return ['ok' => null, 'detail' => 'Not used here.'];
        }
        $res = Http::timeout(3)->get(rtrim((string) config('inertia.ssr.url'), '/').'/health');

        return ['ok' => $res->successful(), 'detail' => $res->successful() ? 'responding' : 'HTTP '.$res->status()];
    }

    /** @return array{ok: bool|null, detail: string} */
    private function scanner(): array
    {
        $host = (string) config('attachments.clamav_host');
        if ($host === '') {
            return ['ok' => null, 'detail' => 'Not configured.'];
        }
        $socket = @fsockopen($host, (int) config('attachments.clamav_port', 3310), $errno, $errstr, 3);
        if ($socket === false) {
            return ['ok' => false, 'detail' => "Can't connect: {$errstr}"];
        }
        stream_set_timeout($socket, 3);
        fwrite($socket, "zPING\0");
        $reply = trim((string) fread($socket, 64), "\0\n ");
        fclose($socket);

        return ['ok' => $reply === 'PONG', 'detail' => $reply === 'PONG' ? 'responding' : 'unexpected reply'];
    }

    /**
     * The nightly backup ran in the last 26 hours. Not checked until the first one is recorded.
     *
     * @return array{ok: bool|null, detail: string}
     */
    private function backup(): array
    {
        $last = OpsRecordCommand::last('backup');
        if ($last === null) {
            return ['ok' => null, 'detail' => 'No backup recorded yet.'];
        }
        $ok = OpsRecordCommand::last('backup', okOnly: true);
        if ($ok === null) {
            return ['ok' => false, 'detail' => 'No backup has succeeded.'];
        }
        $age = (now()->getTimestamp() - $ok['at']) / 3600;
        if ($age > OpsRecordCommand::BACKUP_MAX_AGE_HOURS) {
            return ['ok' => false, 'detail' => 'Last good backup '.round($age).' hours ago.'];
        }

        return ['ok' => true, 'detail' => 'last good one '.round($age, 1).' hours ago'];
    }

    /**
     * At least 10% (or 5 GB) of the disk free where uploads, logs and reports are kept.
     *
     * @return array{ok: bool, detail: string}
     */
    private function disk(): array
    {
        $free = (float) disk_free_space(storage_path());
        $total = (float) disk_total_space(storage_path());
        $share = $total > 0 ? $free / $total : 0.0;

        return ['ok' => $share >= 0.10 || $free >= 5e9, 'detail' => round($free / 1e9, 1).' GB free ('.round($share * 100).'%)'];
    }
}
