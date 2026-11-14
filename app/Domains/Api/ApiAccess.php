<?php

declare(strict_types=1);

namespace App\Domains\Api;

use App\Domains\Network\Seats\Models\Team;

/**
 * What a team's API access includes (Sprint 44). Every seat holder gets the API; the "API plan" add-on (wired into
 * billing in 44b) raises the request rate and the number of webhook endpoints.
 */
final class ApiAccess
{
    /** Requests a minute per token, without and with the API plan. */
    public const BASE_PER_MINUTE = 60;

    public const PLAN_PER_MINUTE = 600;

    /** Whether the team has the API plan (the add-on arrives with billing in 44b; until then nobody has it). */
    public static function hasPlan(Team $team): bool
    {
        return false;
    }

    public static function perMinute(ApiToken $token): int
    {
        return self::hasPlan($token->team) ? self::PLAN_PER_MINUTE : self::BASE_PER_MINUTE;
    }
}
