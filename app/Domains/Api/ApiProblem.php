<?php

declare(strict_types=1);

namespace App\Domains\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every API error in one format (RFC 9457 "problem details"): {type, title, status, detail, errors?}, so integrations
 * handle errors one way. Server errors never expose internals.
 */
final class ApiProblem
{
    public static function from(Throwable $e): JsonResponse
    {
        [$status, $title, $detail, $errors, $headers] = match (true) {
            $e instanceof ValidationException => [422, 'Invalid request', 'Some fields need fixing.', $e->errors(), []],
            $e instanceof AuthenticationException => [401, 'Not authenticated', $e->getMessage() ?: 'Missing or invalid API token.', null, ['WWW-Authenticate' => 'Bearer']],
            $e instanceof AuthorizationException => [403, 'Not allowed', $e->getMessage() ?: 'This token can’t do that.', null, []],
            $e instanceof ModelNotFoundException => [404, 'Not found', 'No such resource, or your team can’t see it.', null, []],
            $e instanceof ThrottleRequestsException => [429, 'Too many requests', 'Slow down: see the Retry-After header.', null, $e->getHeaders()],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), self::title($e->getStatusCode()), $e->getMessage() ?: self::title($e->getStatusCode()), null, $e->getHeaders()],
            default => [500, 'Server error', 'Something went wrong on our side. It has been logged; please try again.', null, []],
        };
        $body = ['type' => 'https://teamtoteamreferrals.com/developers#errors-'.$status, 'title' => $title, 'status' => $status, 'detail' => $detail];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, [...$headers, 'Content-Type' => 'application/problem+json']);
    }

    private static function title(int $status): string
    {
        return match ($status) {
            400 => 'Bad request', 401 => 'Not authenticated', 403 => 'Not allowed', 404 => 'Not found', 405 => 'Method not allowed',
            409 => 'Conflict', 410 => 'Gone', 422 => 'Invalid request', 429 => 'Too many requests', 503 => 'Unavailable', default => 'Error',
        };
    }
}
