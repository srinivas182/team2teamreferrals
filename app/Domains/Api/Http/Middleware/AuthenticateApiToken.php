<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Middleware;

use App\Domains\Api\ApiToken;
use App\Domains\Api\ApiTokens;
use App\Domains\Api\Sandbox;
use App\Domains\Network\Seats\Models\Team;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Authorization: Bearer t2t_live_…": finds the token and acts as the person who created it. A test token acts as
 * the team's sandbox twin instead, so it can never touch real referrals or contact anyone.
 */
final class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiTokens::find($request->bearerToken());
        if ($token === null) {
            throw new AuthenticationException('Missing, invalid, expired or revoked API token.');
        }
        $team = $token->team;
        $user = $token->user;
        if ($token->isTest()) {
            $team = Sandbox::twin($token->team);
            $user = $team->leader;
        } elseif (Team::of($user)?->id !== $team->id) {
            throw new AuthenticationException('This token’s owner is no longer on the team.');
        }
        $request->attributes->set('api.token', $token);
        $request->attributes->set('api.team', $team);
        auth()->setUser($user);
        // Last used: written at most once a minute per token.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            ApiToken::query()->whereKey($token->id)->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        }

        return $next($request);
    }
}
