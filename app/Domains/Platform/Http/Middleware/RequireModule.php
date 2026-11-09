<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Platform\Modules\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard: `module:community`. A switched-off module behaves as if it doesn't exist. */
final class RequireModule
{
    public function __construct(private readonly Modules $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->enabled($module), 404);

        return $next($request);
    }
}
