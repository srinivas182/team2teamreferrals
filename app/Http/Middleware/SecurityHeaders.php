<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Secure defaults on every response. The CSP allows only our own origin plus a
 * per-request nonce for the inline theme script; JSON-LD data blocks are not executed.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $dev = app()->environment('local') ? ' http://localhost:5173 ws://localhost:5173 http://127.0.0.1:5173 ws://127.0.0.1:5173' : '';
        // Stripe's secure card form loads only on the billing pages, keeping the rest of the site's policy tight.
        $stripe = $request->is('app/billing*', 'app/company/billing*');
        $csp = implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".($stripe ? ' https://js.stripe.com' : '').$dev,
            "style-src 'self' 'unsafe-inline'".$dev,
            "img-src 'self' data: blob:".($stripe ? ' https://*.stripe.com' : ''),
            "font-src 'self' data:",
            "connect-src 'self'".($stripe ? ' https://api.stripe.com' : '').$dev,
            $stripe ? 'frame-src https://js.stripe.com https://hooks.stripe.com' : null,
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]));

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(self), geolocation=(), payment=(self)',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if (app()->environment('production')) {
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload';
        }
        // Widget pages set their own policy (shown only on the member's own websites), so they mustn't be denied framing.
        if ($request->is('embed/*') && $response->headers->has('Content-Security-Policy')) {
            unset($headers['X-Frame-Options'], $headers['Cross-Origin-Opener-Policy']);
        }
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
