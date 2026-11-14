<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Middleware;

use App\Domains\Api\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * Safe retries (Stripe-style): a request sent again with the same "Idempotency-Key" header gets the first answer
 * back instead of being done twice, so a network glitch can never create two referrals. Keys last 24 hours.
 */
final class Idempotency
{
    public const HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $token = $request->attributes->get('api.token');
        if ($key === null || $request->isMethod('GET') || ! $token instanceof ApiToken) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw new UnprocessableEntityHttpException('Idempotency-Key must be 1 to 100 letters, digits, dots, colons, dashes or underscores.');
        }
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $saved = DB::table('api_idempotency_keys')->where(['api_token_id' => $token->id, 'key' => $key])->where('created_at', '>', now()->subHours(self::HOURS))->first();
        if ($saved !== null) {
            if (! hash_equals((string) $saved->request_hash, $hash)) {
                throw new UnprocessableEntityHttpException('This Idempotency-Key was already used for a different request.');
            }
            if ($saved->status === null) {
                throw new ConflictHttpException('A request with this Idempotency-Key is still being processed.');
            }

            return response((string) $saved->body, (int) $saved->status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }
        DB::table('api_idempotency_keys')->where(['api_token_id' => $token->id, 'key' => $key])->delete(); // an expired one
        DB::table('api_idempotency_keys')->insert(['api_token_id' => $token->id, 'key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::table('api_idempotency_keys')->where(['api_token_id' => $token->id, 'key' => $key])->delete();
            throw $e;
        }
        // Server errors aren't saved, so the request can be tried again.
        if ($response->getStatusCode() >= 500) {
            DB::table('api_idempotency_keys')->where(['api_token_id' => $token->id, 'key' => $key])->delete();
        } else {
            DB::table('api_idempotency_keys')->where(['api_token_id' => $token->id, 'key' => $key])->update(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()]);
        }

        return $response;
    }
}
