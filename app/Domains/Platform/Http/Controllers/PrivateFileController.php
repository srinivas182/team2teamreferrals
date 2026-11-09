<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Files\PrivateFiles;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves a private file only through a signed, expiring link (local storage; S3 links come straight from the bucket). */
final class PrivateFileController
{
    public function __invoke(string $path): StreamedResponse
    {
        abort_if(str_contains($path, '..'), 404);
        $disk = Storage::disk(PrivateFiles::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
