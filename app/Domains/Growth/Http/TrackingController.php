<?php

declare(strict_types=1);

namespace App\Domains\Growth\Http;

use App\Domains\Growth\Attribution;
use App\Domains\Growth\Campaigns;
use App\Domains\Growth\Tracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Campaign email clicks and opens, and the email provider's bounce and complaint events. */
final class TrackingController
{
    public function click(Request $request, string $send): RedirectResponse
    {
        $to = (string) $request->query('u', '');
        abort_unless($to !== '' && Tracking::valid($send, $to, (string) $request->query('s', '')), 404);
        $campaign = Tracking::click($send);
        $r = redirect()->away($to);
        if ($campaign !== null) {
            $r->withCookie(cookie(Attribution::CAMPAIGN, (string) $campaign, Attribution::DAYS * 24 * 60, null, null, null, true, false, 'lax'));
        }

        return $r;
    }

    /**
     * The visit beacon from public pages (they're cached at the edge, so they can't set cookies themselves): the first
     * visit is remembered, and so is the latest one with a source.
     */
    public function visit(Request $request): Response
    {
        $d = $request->validate(['path' => ['required', 'string', 'max:255', 'starts_with:/'], 'referrer' => ['nullable', 'string', 'max:500'], 'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:100'], 'utm.medium' => ['nullable', 'string', 'max:100'], 'utm.campaign' => ['nullable', 'string', 'max:100']]);
        $response = response()->noContent();
        if ($request->user() !== null || preg_match('#^/(app|admin)(/|$)#', (string) $d['path'])) {
            return $response;
        }
        $visit = Attribution::visit((string) $d['path'], $d['referrer'] ?? null, (array) ($d['utm'] ?? []), $request->getHost());
        $minutes = Attribution::DAYS * 24 * 60;
        if ($request->cookie(Attribution::FIRST) === null) {
            $response->headers->setCookie(cookie(Attribution::FIRST, (string) json_encode($visit), $minutes, null, null, null, true, false, 'lax'));
        }
        if ($visit['source'] !== 'direct') {
            $response->headers->setCookie(cookie(Attribution::LAST, (string) json_encode($visit), $minutes, null, null, null, true, false, 'lax'));
        }

        return $response;
    }

    public function open(string $send): Response
    {
        Tracking::open($send);

        return response((string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, max-age=0']);
    }

    /**
     * Bounces and spam complaints (Postmark's bounce and spam-complaint webhooks, or {type: bounce|complaint, email}).
     * Protected by the same shared secret as inbound email.
     */
    public function emailEvents(Request $request): JsonResponse
    {
        $secret = (string) config('mail.inbound.secret');
        $given = (string) ($request->header('X-Inbound-Secret') ?: $request->getPassword());
        abort_unless($secret !== '' && hash_equals($secret, $given), 401);
        $record = (string) $request->input('RecordType', '');
        $email = (string) ($request->input('Email') ?: $request->input('email', ''));
        $kind = match (true) {
            $record === 'SpamComplaint', $request->input('type') === 'complaint' => 'complaint',
            $record === 'Bounce' && in_array((string) $request->input('Type'), ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated', 'SpamNotification', 'Blocked'], true), $request->input('type') === 'bounce' => 'bounce',
            default => null,
        };
        if ($kind === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json(['status' => 'ignored']);
        }
        Campaigns::bounced($email, $kind);

        return response()->json(['status' => $kind]);
    }
}
