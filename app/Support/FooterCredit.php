<?php

declare(strict_types=1);

namespace App\Support;

use App\Domains\Platform\Settings\Settings;

/** The "Powered by MCS" credit in every footer, managed by super admins (Admin → System → General). */
final class FooterCredit
{
    public const DEFAULT_TEXT = 'Powered by MCS';

    /** @return array{enabled: bool, text: string, url: ?string} */
    public static function settings(): array
    {
        $s = app(Settings::class);

        return ['enabled' => $s->get('footer.credit_enabled', '1') === '1', 'text' => (string) $s->get('footer.credit_text', self::DEFAULT_TEXT), 'url' => $s->get('footer.credit_url')];
    }

    /** @return array{text: string, url: ?string}|null what the footer shows, or null when it's switched off */
    public static function shown(): ?array
    {
        $c = self::settings();
        $url = $c['url'] !== null && str_starts_with($c['url'], 'https://') ? $c['url'] : null;

        return $c['enabled'] && trim($c['text']) !== '' ? ['text' => $c['text'], 'url' => $url] : null;
    }
}
