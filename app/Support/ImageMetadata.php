<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes metadata (EXIF, including GPS location; XMP; text comments) from JPEG, PNG and WebP files without
 * re-encoding, so no image library is needed and the picture itself is untouched. Also identifies the format from the
 * file's own bytes rather than its name or the browser's claim.
 */
final class ImageMetadata
{
    public static function type(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    public static function strip(string $bytes): string
    {
        return match (self::type($bytes)) {
            'image/jpeg' => self::jpeg($bytes),
            'image/png' => self::png($bytes),
            'image/webp' => self::webp($bytes),
            default => $bytes,
        };
    }

    /** Keep the image data; drop APP1–APP15 (EXIF/GPS, XMP, maker notes) and comments. APP0 (JFIF) stays. */
    private static function jpeg(string $b): string
    {
        $out = "\xFF\xD8";
        $i = 2;
        $n = strlen($b);
        while ($i + 4 <= $n && $b[$i] === "\xFF") {
            $marker = ord($b[$i + 1]);
            if ($marker === 0xDA) { // start of scan: the rest is image data
                return $out.substr($b, $i);
            }
            $len = (ord($b[$i + 2]) << 8) + ord($b[$i + 3]);
            $segment = substr($b, $i, $len + 2);
            if (! (($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE)) {
                $out .= $segment;
            }
            $i += $len + 2;
        }

        return $out.substr($b, $i);
    }

    private static function png(string $b): string
    {
        $out = substr($b, 0, 8);
        $i = 8;
        while ($i + 12 <= strlen($b)) {
            $u = unpack('N', substr($b, $i, 4));
            if ($u === false) {
                break; // truncated file: keep what is complete
            }
            $len = (int) $u[1];
            $type = substr($b, $i + 4, 4);
            if (! in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $out .= substr($b, $i, $len + 12);
            }
            $i += $len + 12;
        }

        return $out;
    }

    private static function webp(string $b): string
    {
        $chunks = '';
        $i = 12;
        while ($i + 8 <= strlen($b)) {
            $type = substr($b, $i, 4);
            $u = unpack('V', substr($b, $i + 4, 4));
            if ($u === false) {
                break; // truncated file: keep what is complete
            }
            $len = (int) $u[1];
            $size = 8 + $len + ($len % 2);
            if (! in_array($type, ['EXIF', 'XMP '], true)) {
                $chunk = substr($b, $i, $size);
                if ($type === 'VP8X') { // clear the "has EXIF / has XMP" flags
                    $flags = ord($chunk[8]) & ~0x0C;
                    $chunk[8] = chr($flags);
                }
                $chunks .= $chunk;
            }
            $i += $size;
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }
}
