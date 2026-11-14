<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\ApiToken;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Illuminate\Http\Request;

/** Shared helpers: who is calling, and the referrals their team can see. */
abstract class Controller
{
    protected function token(Request $request): ApiToken
    {
        $t = $request->attributes->get('api.token');
        abort_unless($t instanceof ApiToken, 401);

        return $t;
    }

    /** The team the API acts for (the sandbox twin for a test token). */
    protected function team(Request $request): Team
    {
        $t = $request->attributes->get('api.team');
        abort_unless($t instanceof Team, 401);

        return $t;
    }

    protected function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }

    protected function referral(Request $request, string $id): Referral
    {
        $team = $this->team($request);

        return Referral::query()->where('ulid', $id)->where(fn ($q) => $q->where('sender_team_id', $team->id)->orWhere('receiver_team_id', $team->id))->firstOrFail();
    }

    protected function limit(Request $request): int
    {
        return max(1, min(100, (int) $request->query('limit', '25')));
    }
}
