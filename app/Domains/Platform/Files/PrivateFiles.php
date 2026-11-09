<?php

declare(strict_types=1);

namespace App\Domains\Platform\Files;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** Licenses, W-9s and agreements are never public: they open only through links that expire. */
final class PrivateFiles
{
    public const DISK = 'private';

    public static function url(string $path, int $minutes = 10): string
    {
        $disk = Storage::disk(self::DISK);
        if (config('filesystems.disks.private.driver') === 's3') {
            return $disk->temporaryUrl($path, now()->addMinutes($minutes));
        }

        return URL::temporarySignedRoute('files.private', now()->addMinutes($minutes), ['path' => $path]);
    }
}
