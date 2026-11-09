<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Settings\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-controlled maintenance: members see the branded "We'll be right back" page; staff can still sign in
 * and work; provider webhooks and health checks keep flowing so nothing is lost.
 */
final class MaintenanceGate
{
    private const OPEN = ['login', 'logout', 'two-factor-challenge', 'user/confirm-password', 'admin', 'admin/*', 'webhooks/*', 'up', 'health', 'status', 'build/*', 'brand/*', 'sw.js', 'robots.txt', 'favicon.ico', 'site.webmanifest', 'passkeys/*'];

    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->settings->get('platform.maintenance') !== '1' || $request->is(...self::OPEN)) {
            return $next($request);
        }
        $user = $request->user();
        if ($user instanceof User && $user->isStaff()) {
            return $next($request);
        }
        abort(503);
    }
}
