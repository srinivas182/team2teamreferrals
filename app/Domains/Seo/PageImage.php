<?php

declare(strict_types=1);

namespace App\Domains\Seo;

use App\Domains\Network\Prelaunch\ShareImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * A preview image for each public page (Sprint 43): the page's own name, place and status on a branded card, so a
 * link shared in a message, on social media or in an AI answer shows what the page is about. The card's text travels
 * in the signed image address, so nothing is stored until the image is first asked for, and a change of status makes
 * a new address (social networks re-fetch it).
 */
final class PageImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /**
     * @param  array{eyebrow: string, title: string, line?: string, badge?: string}  $card
     * @return string the image's path, for SeoData::$image
     */
    public static function path(array $card): string
    {
        $card = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 120), array_filter($card, fn ($v) => $v !== ''));
        $payload = rtrim(strtr(base64_encode((string) json_encode($card)), '+/', '-_'), '=');

        return '/og/'.$payload.'.'.self::sign($payload).'.png';
    }

    /** GET /og/{payload}.{signature}.png */
    public static function serve(string $payload, string $signature): Response|RedirectResponse
    {
        abort_unless(hash_equals(self::sign($payload), $signature), 404);
        $card = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        abort_unless(is_array($card) && isset($card['eyebrow'], $card['title']), 404);
        $file = 'og/'.sha1($payload).'.png';
        $disk = Storage::disk('public');
        if (! $disk->exists($file)) {
            if (! ShareImage::available()) {
                return redirect((string) config('seo.default_image'));
            }
            $disk->put($file, ShareImage::png(self::svg($card)), ['visibility' => 'public']);
        }

        return response((string) $disk->get($file), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    /** @param array<string, string> $card */
    public static function svg(array $card): string
    {
        [$w, $h] = [self::WIDTH, self::HEIGHT];
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
        $f = 'font-family="Inter, Arial, sans-serif"';
        // The title on up to two lines, sized to fit (Inter ExtraBold averages ~0.56 em per character).
        $lines = self::wrap((string) $card['title'], 26);
        $longest = max(1, ...array_map('mb_strlen', $lines));
        $size = (int) min(84, floor($w * 0.84 / max(1, $longest * 0.56)));
        $y = 250;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">'
            .'<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0E1B3D"/><stop offset="1" stop-color="#1B2A5C"/></linearGradient></defs>'
            .'<rect width="'.$w.'" height="'.$h.'" fill="url(#bg)"/>'
            .'<circle cx="'.($w * 0.9).'" cy="'.($h * 0.1).'" r="'.($w * 0.2).'" fill="#FF7A1A" opacity=".16"/>'
            .'<circle cx="'.($w * 0.05).'" cy="'.($h * 0.95).'" r="'.($w * 0.22).'" fill="#2451F5" opacity=".26"/>'
            .'<text x="84" y="150" fill="#AAB4CE" '.$f.' font-size="30" font-weight="600" letter-spacing="1">'.$e(mb_strtoupper((string) $card['eyebrow'])).'</text>';
        foreach ($lines as $i => $line) {
            $svg .= '<text x="84" y="'.($y + $i * $size * 1.12).'" fill="#fff" '.$f.' font-size="'.$size.'" font-weight="800">'.$e($line).'</text>';
        }
        $after = $y + (count($lines) - 1) * $size * 1.12 + 70;
        if (($card['line'] ?? '') !== '') {
            $svg .= '<text x="84" y="'.$after.'" fill="#C9D2EA" '.$f.' font-size="34" font-weight="500">'.$e((string) $card['line']).'</text>';
        }
        if (($card['badge'] ?? '') !== '') {
            $bw = (int) (mb_strlen((string) $card['badge']) * 17 + 56);
            $svg .= '<rect x="84" y="500" width="'.$bw.'" height="58" rx="29" fill="#2451F5"/>'
                .'<text x="'.(84 + $bw / 2).'" y="539" text-anchor="middle" fill="#fff" '.$f.' font-size="28" font-weight="700">'.$e((string) $card['badge']).'</text>';
        }

        return $svg.'<text x="'.($w - 84).'" y="'.($h - 64).'" text-anchor="end" fill="#fff" '.$f.' font-size="28" font-weight="700">teamtoteamreferrals.com</text></svg>';
    }

    /** @return list<string> at most two lines, the second shortened if needed */
    private static function wrap(string $text, int $width): array
    {
        $lines = explode("\n", wordwrap($text, $width, "\n", true));
        if (count($lines) <= 2) {
            return $lines;
        }

        return [$lines[0], mb_strimwidth(implode(' ', array_slice($lines, 1)), 0, $width + 4, '…')];
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'og|'.$payload, (string) config('app.key')), 0, 16);
    }
}
