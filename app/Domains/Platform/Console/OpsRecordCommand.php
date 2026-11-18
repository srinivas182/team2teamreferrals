<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Go-live (Sprint 54a): the server's scripts record each backup, restore drill and deploy here, so t2t:health can
 * say when backups stop and Admin can show when the last one ran. Kept in Redis (append-only, so it survives
 * restarts); losing it only means "not recorded yet" until the next run.
 */
final class OpsRecordCommand extends Command
{
    public const KINDS = ['backup', 'restore-drill', 'deploy'];

    /** A backup older than this fails t2t:health (they run nightly). */
    public const BACKUP_MAX_AGE_HOURS = 26;

    protected $signature = 't2t:ops:record {kind : backup, restore-drill or deploy} {status : ok or failed} {detail? : One line about it}';

    protected $description = 'Record a backup, restore drill or deploy (used by the server scripts)';

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        $status = (string) $this->argument('status');
        if (! in_array($kind, self::KINDS, true) || ! in_array($status, ['ok', 'failed'], true)) {
            $this->error('Kind must be one of '.implode(', ', self::KINDS).'; status ok or failed.');

            return self::INVALID;
        }
        $entry = ['status' => $status, 'at' => now()->getTimestamp(), 'detail' => mb_substr((string) $this->argument('detail'), 0, 300)];
        Cache::forever("ops:{$kind}:last", $entry);
        if ($status === 'ok') {
            Cache::forever("ops:{$kind}:last-ok", $entry);
        }
        $this->info("Recorded {$kind} {$status}.");

        return self::SUCCESS;
    }

    /** @return array{status: string, at: int, detail: string}|null */
    public static function last(string $kind, bool $okOnly = false): ?array
    {
        $v = Cache::get("ops:{$kind}:".($okOnly ? 'last-ok' : 'last'));
        if (! is_array($v) || ! isset($v['status'], $v['at'])) {
            return null;
        }

        return ['status' => (string) $v['status'], 'at' => (int) $v['at'], 'detail' => (string) ($v['detail'] ?? '')];
    }
}
