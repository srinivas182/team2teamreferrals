<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Platform\Deliveries\MessageDelivery;
use App\Domains\Platform\Deliveries\SmsOptOut;
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

    /**
     * Replies to our number. STOP-type keywords opt the number out of every text; START opts back in;
     * HELP gets a short answer. Required for US texting (TCPA and carrier rules).
     */
    public function inbound(Request $request, Settings $settings): Response
    {
        $token = (string) $settings->get('sms.twilio.token');
        abort_unless($token !== '' && self::validSignature($request, $token), 403);

        // WhatsApp messages arrive as "whatsapp:+1…"; the same number serves both channels.
        $raw = (string) $request->input('From');
        $channel = str_starts_with($raw, 'whatsapp:') ? 'whatsapp' : 'sms';
        $from = PhoneNumber::normalize(str_replace('whatsapp:', '', $raw)) ?? $raw;
        $word = strtoupper(trim((string) $request->input('Body')));
        $reply = null;
        $isKeyword = in_array($word, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'START', 'UNSTOP', 'HELP', 'INFO'], true);
        // A verified member's phone talks to the referral bot (opt-out and help keywords still come first).
        $member = $isKeyword ? null : \App\Domains\Identity\Models\User::query()->where('phone', $from)->whereNotNull('phone_verified_at')->first();
        if ($member !== null) {
            $reply = app(\App\Domains\Referrals\Bot\Bot::class)->handle($member, $channel, $from, (string) $request->input('Body'));
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Response>'.($reply !== '' ? '<Message>'.htmlspecialchars($reply, ENT_XML1).'</Message>' : '').'</Response>';

            return response($xml, 200, ['Content-Type' => 'text/xml']);
        }
        if (in_array($word, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT'], true)) {
            SmsOptOut::query()->updateOrCreate(['phone' => $from], ['opted_out_at' => now()]);
            activity('security')->event('sms-opted-out')->withProperties(['phone' => $from])->log('Number replied STOP');
        } elseif ($word === 'YES' && app(\App\Domains\Referrals\Referrals::class)->confirmByReply($from)) {
            // A client confirming a referral by text (the confirmation sends their status link).
            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
        } elseif (in_array($word, ['START', 'YES', 'UNSTOP'], true)) {
            SmsOptOut::query()->where('phone', $from)->delete();
            activity('security')->event('sms-opted-in')->withProperties(['phone' => $from])->log('Number replied START');
        } elseif (in_array($word, ['HELP', 'INFO'], true)) {
            $reply = 'Team to Team Referrals: referral alerts and codes. Msg & data rates may apply. Reply STOP to opt out. Help: '.($settings->get('general.support_email') ?? 'support@teamtoteamreferrals.com');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Response>'.($reply ? '<Message>'.htmlspecialchars($reply, ENT_XML1).'</Message>' : '').'</Response>';

        return response($xml, 200, ['Content-Type' => 'text/xml']);
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
