<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Identity\Models\User;
use App\Support\ImageMetadata;
use App\Support\Scanning\VirusScanner;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The media library: images are identified by their bytes, virus-scanned, and re-encoded as WebP (at most 1600 px
 * wide, plus a 480 px version), which also removes location and camera data. A description (alt text) is required.
 */
final class MediaLibrary
{
    public const MAX_WIDTH = 1600;

    public const THUMB_WIDTH = 480;

    public function __construct(private readonly VirusScanner $scanner) {}

    public function store(UploadedFile $file, string $alt, User $by): string
    {
        $alt = trim($alt);
        if (mb_strlen($alt) < 5) {
            throw ValidationException::withMessages(['alt' => 'Describe the image in a few words (alt text): it’s read aloud by screen readers and used by answer engines.']);
        }
        $bytes = (string) $file->get();
        if (ImageMetadata::type($bytes) === null) {
            throw ValidationException::withMessages(['file' => 'Upload a JPEG, PNG or WebP image.']);
        }
        $verdict = $this->scanner->scan((string) $file->getRealPath());
        if ($verdict === 'infected' || ($verdict !== 'clean' && (bool) config('attachments.require_scan'))) {
            throw ValidationException::withMessages(['file' => $verdict === 'infected' ? 'This file was flagged by the virus scan.' : 'Images can’t be checked right now. Please try again shortly.']);
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw ValidationException::withMessages(['file' => 'That image couldn’t be read.']);
        }
        $ulid = (string) Str::ulid();
        $disk = Storage::disk(config('filesystems.default'));
        [$main, $w, $h] = self::webp($img, self::MAX_WIDTH);
        [$thumb] = self::webp($img, self::THUMB_WIDTH);
        imagedestroy($img);
        $disk->put("media/{$ulid}.webp", $main);
        $disk->put("media/{$ulid}-480.webp", $thumb);
        DB::table('media')->insert(['ulid' => $ulid, 'path' => "media/{$ulid}.webp", 'thumb_path' => "media/{$ulid}-480.webp", 'width' => $w, 'height' => $h, 'bytes' => strlen($main),
            'alt' => mb_substr($alt, 0, 160), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 160), 'uploaded_by' => $by->id, 'created_at' => now()]);

        return $ulid;
    }

    /** @return array{0: string, 1: int, 2: int} WebP bytes, width, height */
    private static function webp(GdImage $img, int $maxWidth): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > $maxWidth) {
            $nh = max(1, (int) round($h * $maxWidth / $w));
            $scaled = imagescale($img, $maxWidth, $nh, IMG_BICUBIC);
            [$img, $w, $h] = [$scaled !== false ? $scaled : $img, $maxWidth, $nh];
        }
        imagepalettetotruecolor($img);
        imagesavealpha($img, true);
        ob_start();
        imagewebp($img, null, 82);

        return [(string) ob_get_clean(), $w, $h];
    }

    public static function url(string $path): string
    {
        return Storage::disk(config('filesystems.default'))->url($path);
    }
}
