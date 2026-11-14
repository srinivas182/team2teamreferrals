<?php

declare(strict_types=1);

namespace App\Domains\Integrations;

use App\Domains\Integrations\Providers\BoldTrail;
use App\Domains\Integrations\Providers\CrmProvider;
use App\Domains\Integrations\Providers\FollowUpBoss;
use App\Domains\Integrations\Providers\Lofty;
use App\Domains\Platform\Settings\Settings;
use InvalidArgumentException;

/** The CRMs teams can connect, and whether each is switched on (Admin → Integrations). */
final class Connectors
{
    public const PROVIDERS = ['followupboss' => FollowUpBoss::class, 'boldtrail' => BoldTrail::class, 'lofty' => Lofty::class];

    public static function get(string $key): CrmProvider
    {
        $class = self::PROVIDERS[$key] ?? throw new InvalidArgumentException("Unknown CRM {$key}");

        return app($class);
    }

    /** On unless an admin switched it off, and only once the platform side is set up. */
    public static function available(string $key): bool
    {
        return isset(self::PROVIDERS[$key]) && app(Settings::class)->get("integrations.{$key}.enabled", '1') === '1' && self::get($key)->ready();
    }

    /** @return array<string, CrmProvider> */
    public static function all(): array
    {
        return array_map(fn (string $c) => app($c), self::PROVIDERS);
    }
}
