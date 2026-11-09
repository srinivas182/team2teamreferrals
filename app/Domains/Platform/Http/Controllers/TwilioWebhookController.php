<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Deliveries\MessageDelivery;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Delivery receipts from Twilio, verified with Twilio's request signature. */
final class TwilioWebhookController
{
    public function status(Request $request, Settings $settings): Response
    {
        $token = (string) $settings->get('sms.twilio.token');
        abort_unless($token !== '' && self::validSignature($request, $token), 403);

        $status = match ((string) $request->input('MessageStatus')) {
            'delivered' => 'delivered',
            'failed', 'undelivered' => 'failed',
            default => null,
        };
        if ($status !== null) {
            MessageDelivery::query()->where('provider_message_id', (string) $request->input('MessageSid'))
                ->update(['status' => $status, 'error' => $request->input('ErrorCode') ? 'Twilio error '.$request->input('ErrorCode') : null, 'updated_at' => now()]);
        }

        return response('', 204);
    }

    /** https://www.twilio.com/docs/usage/security — HMAC-SHA1 of the URL plus sorted POST params. */
    public static function validSignature(Request $request, string $token): bool
    {
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $k => $v) {
            $data .= $k.(is_scalar($v) ? (string) $v : '');
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));

        return hash_equals($expected, (string) $request->header('X-Twilio-Signature'));
    }
}
