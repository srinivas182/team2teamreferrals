<?php

declare(strict_types=1);

namespace App\Domains\Platform\Settings;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Key/value settings edited from the admin. Values are cached as one map; secrets are
 * encrypted in the database and only leave this class decrypted for runtime use,
 * never for display (see masked()).
 */
final class Settings
{
    private const CACHE_KEY = 'platform-settings:v1';

    /** @var array<string, array{value: ?string, secret: bool}>|null */
    private ?array $loaded = null;

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->all()[$key] ?? null;
        if ($row === null || $row['value'] === null || $row['value'] === '') {
            return $default;
        }

        return $row['secret'] ? Crypt::decryptString($row['value']) : $row['value'];
    }

    public function has(string $key): bool
    {
        $row = $this->all()[$key] ?? null;

        return $row !== null && $row['value'] !== null && $row['value'] !== '';
    }

    public function set(string $key, ?string $value, bool $secret = false, ?int $userId = null): void
    {
        $stored = ($secret && $value !== null && $value !== '') ? Crypt::encryptString($value) : $value;
        DB::table('settings')->updateOrInsert(['key' => $key], [
            'value' => $stored,
            'is_secret' => $secret,
            'updated_by' => $userId,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
        $this->flush();
    }

    /** For display: secrets show only their last four characters. */
    public function masked(string $key): ?string
    {
        $row = $this->all()[$key] ?? null;
        if ($row === null || $row['value'] === null || $row['value'] === '') {
            return null;
        }
        if (! $row['secret']) {
            return $row['value'];
        }

        return '••••••••'.substr(Crypt::decryptString($row['value']), -4);
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array{value: ?string, secret: bool}> */
    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        // The app also boots where no database exists yet (composer's package:discover in CI, a fresh
        // install before migrations): then fall back to environment defaults and cache nothing.
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                /** @var array<string, array{value: ?string, secret: bool}> $cached */
                return $this->loaded = $cached;
            }
            $map = DB::table('settings')->get(['key', 'value', 'is_secret'])
                ->mapWithKeys(fn ($r) => [(string) $r->key => ['value' => $r->value === null ? null : (string) $r->value, 'secret' => (bool) $r->is_secret]])
                ->all();
            Cache::forever(self::CACHE_KEY, $map);
        } catch (QueryException|PDOException) {
            return [];
        }

        return $this->loaded = $map;
    }
}
