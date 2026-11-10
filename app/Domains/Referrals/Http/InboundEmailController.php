<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Referrals\InboundReplies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound email webhook (provider-neutral). Postmark's parsed JSON is understood as-is; other providers can post
 * {to, from, text, message_id}. Protected by a shared secret (header or HTTP basic password). Always answers 200
 * once authenticated, so providers don't retry emails we chose to ignore.
 */
final class InboundEmailController
{
    public function __invoke(Request $request, InboundReplies $replies): JsonResponse
    {
        $secret = (string) config('mail.inbound.secret');
        $given = (string) ($request->header('X-Inbound-Secret') ?: $request->getPassword());
        abort_unless($secret !== '' && hash_equals($secret, $given), 401);

        $to = (string) ($request->input('OriginalRecipient') ?: $request->input('To') ?: $request->input('to', ''));
        $from = (string) ($request->input('FromFull.Email') ?: $request->input('From') ?: $request->input('from', ''));
        $text = (string) ($request->input('StrippedTextReply') ?: $request->input('TextBody') ?: $request->input('text', ''));
        $id = (string) ($request->input('MessageID') ?: $request->input('message_id') ?: hash('sha256', $to.$from.$text));

        return response()->json(['status' => $replies->handle($to, $from, $text, $id)]);
    }
}
