<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Middleware;

use App\Domains\Api\ApiAccess;
use App\Domains\Api\ApiToken;
use App\Domains\Network\Seats\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * For teams with the API plan, records each request (method, path, status, time taken and the X-Request-Id the
 * platform gives every response; never bodies or tokens) in the 30-day request log shown in Developers (API).
 */
final class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);
        $id = mb_substr((string) $request->attributes->get('request_id', ''), 0, 40);
        $token = $request->attributes->get('api.token');
        $team = $request->attributes->get('api.team');
        if ($token instanceof ApiToken && $team instanceof Team) {
            // The plan belongs to the real team, also for test tokens (which act as its sandbox twin).
            $owner = $token->team;
            if (ApiAccess::hasPlan($owner)) {
                DB::table('api_request_logs')->insert(['team_id' => $owner->id, 'api_token_id' => $token->id, 'method' => $request->method(), 'path' => mb_substr($request->getRequestUri(), 0, 255),
                    'status' => $response->getStatusCode(), 'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000), 'ip' => $request->ip(), 'request_id' => $id, 'created_at' => now()]);
            }
        }

        return $response;
    }
}
