<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Middleware;

use App\Domains\Api\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** "api.scope:referrals:write": the token must have been given that permission. */
final class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->attributes->get('api.token');
        if (! $token instanceof ApiToken || ! $token->can($scope)) {
            throw new AccessDeniedHttpException("This token doesn’t have the {$scope} permission.");
        }

        return $next($request);
    }
}
