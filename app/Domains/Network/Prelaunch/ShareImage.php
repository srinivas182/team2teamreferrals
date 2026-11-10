<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * "We claimed Houston" cards, rendered on the server to PNG with librsvg (rsvg-convert) and the Inter font, so social
 * networks show a branded, personal preview. The same design is used for previews, downloads and the share page.
 */
final class ShareImage
{
    public const SIZES = ['og' => [1200, 630], 'square' => [1080, 1080], 'story' => [1080, 1920]];

    /** librsvg renders gradients, transparency and fonts faithfully (ImageMagick's built-in SVG renderer does not). */
    public static function available(): bool
    {
        return (new ExecutableFinder)->find('rsvg-convert') !== null;
    }

    public static function path(string $shareId, string $size): string
    {
        return "share/{$shareId}-{$size}.png";
    }

    public static function svg(string $size, string $business, string $territory, string $group): string
    {
        [$w, $h] = self::SIZES[$size];
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
        // Headline fits the card: Inter ExtraBold averages ~0.56 em per character.
        $longest = max(mb_strlen('We claimed'), mb_strlen($territory) + 1);
        $big = (int) min(round($w * ($size === 'story' ? 0.12 : 0.085)), floor($w * 0.86 / ($longest * 0.56)));
        $y0 = $size === 'story' ? $h * 0.38 : $h * 0.34;
        $f = 'font-family="Inter, Arial, sans-serif"';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">'
            .'<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0E1B3D"/><stop offset="1" stop-color="#1B2A5C"/></linearGradient>'
            .'<linearGradient id="rl" x1="0" x2="1"><stop offset="0" stop-color="#2451F5"/><stop offset="1" stop-color="#FF7A1A"/></linearGradient></defs>'
            .'<rect width="'.$w.'" height="'.$h.'" fill="url(#bg)"/>'
            .'<circle cx="'.($w * 0.88).'" cy="'.($h * 0.12).'" r="'.($w * 0.22).'" fill="#FF7A1A" opacity=".18"/>'
            .'<circle cx="'.($w * 0.1).'" cy="'.($h * 0.9).'" r="'.($w * 0.25).'" fill="#2451F5" opacity=".28"/>'
            .'<path d="M'.($w * 0.12).' '.($h * 0.86).' C '.($w * 0.35).' '.($h * 0.7).', '.($w * 0.65).' '.($h * 0.7).', '.($w * 0.88).' '.($h * 0.8).'" fill="none" stroke="url(#rl)" stroke-width="'.($w * 0.008).'" stroke-linecap="round"/>'
            .'<circle cx="'.($w * 0.12).'" cy="'.($h * 0.86).'" r="'.($w * 0.015).'" fill="#2451F5"/><circle cx="'.($w * 0.88).'" cy="'.($h * 0.8).'" r="'.($w * 0.015).'" fill="#FF7A1A"/>'
            .'<text x="'.($w * 0.07).'" y="'.($y0 - $big * 1.1).'" fill="#AAB4CE" '.$f.' font-size="'.round($w * 0.028).'" font-weight="600">'.$e(mb_strtoupper($group)).'</text>'
            .'<text x="'.($w * 0.07).'" y="'.$y0.'" fill="#fff" '.$f.' font-size="'.$big.'" font-weight="800">We claimed</text>'
            .'<text x="'.($w * 0.07).'" y="'.($y0 + $big * 1.15).'" fill="#fff" '.$f.' font-size="'.$big.'" font-weight="800">'.$e($territory).'.</text>'
            .'<text x="'.($w * 0.07).'" y="'.($y0 + $big * 2.1).'" fill="#C9D2EA" '.$f.' font-style="normal" font-weight="500" font-size="'.round($w * ($size === 'story' ? 0.045 : 0.03)).'">'.$e($business).' · founding member</text>'
            .'<text x="'.($w * 0.93).'" y="'.($h * 0.93).'" text-anchor="end" fill="#fff" '.$f.' font-size="'.round($w * 0.026).'" font-weight="700">teamtoteamreferrals.com</text>'
            .'</svg>';
    }

    public static function png(string $svg): string
    {
        $process = new Process(['rsvg-convert', '--format', 'png']);
        $process->setInput($svg)->setTimeout(20)->mustRun();

        return $process->getOutput();
    }

    /** Render and store all three sizes; returns false when the server can't render (the brand image is used instead). */
    public static function generate(string $shareId, string $business, string $territory, string $group): bool
    {
        if (! self::available()) {
            return false;
        }
        foreach (array_keys(self::SIZES) as $size) {
            Storage::disk('public')->put(self::path($shareId, $size), self::png(self::svg($size, $business, $territory, $group)), ['visibility' => 'public', 'CacheControl' => 'public, max-age=31536000, immutable']);
        }

        return true;
    }
}
