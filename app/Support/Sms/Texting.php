<?php

declare(strict_types=1);

namespace App\Support\Sms;

use App\Domains\Platform\Settings\Settings;

/**
 * Whether the platform sends text messages at all (Admin → System → Text messages). On only with a working provider
 * (Twilio) set up; a super admin can switch it off. Off: nothing is texted, members aren't asked to verify their mobile
 * by text, and alerts reach them by email, in the app and by push instead.
 */
final class Texting
{
    public static function configured(): bool
    {
        $s = app(Settings::class);

        return $s->get('sms.driver') === 'twilio' && $s->has('sms.twilio.sid') && $s->has('sms.twilio.token') && $s->has('sms.twilio.from');
    }

    public static function on(): bool
    {
        return self::configured() && app(Settings::class)->get('sms.enabled') !== '0';
    }
}
