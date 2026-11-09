<?php

declare(strict_types=1);

namespace App\Domains\Platform\Health;

use App\Domains\Platform\Files\PrivateFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Fast checks of everything a request depends on. Used by /health (monitors) and /status (members). */
final class HealthChecks
{
    public const COMPONENTS = ['database' => 'Database', 'cache' => 'Cache and sessions', 'queue' => 'Background jobs', 'storage' => 'File storage', 'search' => 'Search'];

    /** @return array<string, array{ok: bool, ms: int}> */
    public function run(): array
    {
        $checks = [
            'database' => $this->time(fn () => DB::select('select 1')),
            'cache' => $this->time(function () {
                $k = 'health:'.Str::random(8);
                Cache::put($k, 1, 10);
                $ok = Cache::pull($k) === 1;
                if (! $ok) {
                    throw new RuntimeException('cache round trip failed');
                }
            }),
            'queue' => $this->time(fn () => Queue::size()),
            'storage' => $this->time(fn () => Storage::disk(PrivateFiles::DISK)->exists('.health-probe')),
        ];
        if (config('scout.driver') === 'meilisearch') {
            $checks['search'] = $this->time(function () {
                $h = app(\Meilisearch\Client::class)->health();
                if (($h['status'] ?? null) !== 'available') {
                    throw new RuntimeException('Meilisearch unavailable');
                }
            });
        }

        return $checks;
    }

    /** Cached for 30 seconds so the public status page can't be used to load the servers. */
    /** @return array<string, array{ok: bool, ms: int}> */
    public function cached(): array
    {
        try {
            /** @var array<string, array{ok: bool, ms: int}> */
            return Cache::remember('health:last', 30, fn () => $this->run());
        } catch (Throwable) {
            return $this->run();
        }
    }

    /** @return array{ok: bool, ms: int} */
    private function time(callable $check): array
    {
        $start = hrtime(true);
        try {
            $check();
            $ok = true;
        } catch (Throwable $e) {
            report($e);
            $ok = false;
        }

        return ['ok' => $ok, 'ms' => (int) round((hrtime(true) - $start) / 1e6)];
    }
}
