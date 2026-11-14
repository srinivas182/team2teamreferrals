<?php

declare(strict_types=1);

namespace App\Domains\Api;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Team API tokens (Sprint 44a). A token is shown once and stored only as a hash; it acts as the person who created
 * it, within the scopes chosen, and stops working if they leave the team. Test tokens ("t2t_test_…") work in the
 * sandbox; live tokens ("t2t_live_…") on real data.
 */
final class ApiTokens
{
    /** Scope => what it allows, in plain words. */
    public const SCOPES = [
        'referrals:read' => 'See referrals your team sent and received',
        'referrals:write' => 'Send referrals and update them (accept, decline, stage, notes)',
        'leads:read' => 'See leads from your public profile',
        'leads:write' => 'Update the status of those leads',
        'groups:read' => 'See your networks and check who covers a city',
        'seats:read' => 'See your team’s seats',
        'profile:read' => 'See your team profile',
        'profile:write' => 'Update your team profile',
        'webhooks:manage' => 'Add and change webhook endpoints',
    ];

    /** Most tokens a team can have at once. */
    public const MAX_PER_TEAM = 20;

    /** Roles that can create and revoke tokens. */
    public const MANAGERS = ['leader', 'assistant'];

    /**
     * @param  list<string>  $scopes
     * @return array{token: ApiToken, plain: string}
     */
    public static function create(Team $team, User $user, string $name, string $mode, array $scopes, ?string $expiresAt = null): array
    {
        if (! in_array($team->roleOf($user), self::MANAGERS, true)) {
            throw ValidationException::withMessages(['name' => 'Only the team leader or an assistant can create API tokens.']);
        }
        if (! in_array($mode, ['live', 'test'], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose live or test.']);
        }
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes));
        if ($scopes === []) {
            throw ValidationException::withMessages(['scopes' => 'Choose at least one permission.']);
        }
        if (ApiToken::query()->where('team_id', $team->id)->whereNull('revoked_at')->count() >= self::MAX_PER_TEAM) {
            throw ValidationException::withMessages(['name' => 'Your team has the most tokens allowed ('.self::MAX_PER_TEAM.'). Revoke one first.']);
        }
        if ($mode === 'test') {
            Sandbox::twin($team); // make the sandbox ready before the token is used
        }
        $plain = 't2t_'.$mode.'_'.Str::random(40);
        $token = ApiToken::create(['team_id' => $team->id, 'user_id' => $user->id, 'name' => mb_substr(trim($name), 0, 80) ?: 'API token', 'mode' => $mode,
            'token_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 13), 'scopes' => $scopes, 'expires_at' => $expiresAt]);
        activity('api')->causedBy($user)->performedOn($team)->event('api-token-created')->withProperties(['token' => $token->ulid, 'mode' => $mode, 'scopes' => $scopes])->log("API token \"{$token->name}\" created");

        return ['token' => $token, 'plain' => $plain];
    }

    public static function revoke(ApiToken $token, User $user): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
        DB::table('api_idempotency_keys')->where('api_token_id', $token->id)->delete();
        activity('api')->causedBy($user)->performedOn($token->team)->event('api-token-revoked')->withProperties(['token' => $token->ulid])->log("API token \"{$token->name}\" revoked");
    }

    /** The token behind a "Bearer t2t_…" header, if it is valid right now. */
    public static function find(?string $plain): ?ApiToken
    {
        if ($plain === null || ! preg_match('/^t2t_(live|test)_[A-Za-z0-9]{40}$/', $plain)) {
            return null;
        }
        $token = ApiToken::query()->with(['team', 'user'])->where('token_hash', hash('sha256', $plain))->first();
        if ($token === null || $token->revoked_at !== null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }
        // The token acts as its creator: it stops when they leave the team or their account is closed.
        if (! $token->user->is_active || $token->team->roleOf($token->user) === null) {
            return null;
        }

        return $token;
    }
}
