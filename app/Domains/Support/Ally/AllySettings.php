<?php

declare(strict_types=1);

namespace App\Domains\Support\Ally;

use App\Domains\Platform\Settings\Settings;

/**
 * Ally's settings (Admin → Ally). The tone and the topics it must not discuss are edited as a draft, checked with the
 * test questions, then published; limits and who sees Ally apply straight away.
 */
final class AllySettings
{
    public const AUDIENCES = ['visitor' => 'Visitors', 'member' => 'Members', 'partner' => 'Partners'];

    public const DEFAULTS = [
        'tone' => 'Warm, plain and brief, like a helpful colleague. Use short sentences.',
        'blocked' => "Legal advice\nTax or financial advice (including whether to buy, sell or refinance)\nOther members' private information",
    ];

    /** @return array{tone: string, blocked: string} */
    public static function live(): array
    {
        $s = app(Settings::class);

        return ['tone' => (string) $s->get('ally.live.tone', self::DEFAULTS['tone']), 'blocked' => (string) $s->get('ally.live.blocked', self::DEFAULTS['blocked'])];
    }

    /** @return array{tone: string, blocked: string} */
    public static function draft(): array
    {
        $s = app(Settings::class);
        $live = self::live();

        return ['tone' => (string) $s->get('ally.draft.tone', $live['tone']), 'blocked' => (string) $s->get('ally.draft.blocked', $live['blocked'])];
    }

    public static function on(string $audience): bool
    {
        return app(\App\Domains\Platform\Modules\Modules::class)->enabled('ally') && app(Settings::class)->get('ally.audience.'.$audience, '1') === '1';
    }

    public static function int(string $key, int $default): int
    {
        return (int) app(Settings::class)->get('ally.'.$key, (string) $default);
    }

    public static function budget(): float
    {
        return (float) app(Settings::class)->get('ally.daily_budget', '5');
    }
}
