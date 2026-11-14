<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

/** The events a webhook endpoint can subscribe to, and which timeline entries raise them. */
final class WebhookEvents
{
    /** Public event type => what it means. */
    public const TYPES = [
        'referral.sent' => 'A referral was sent, by your team or to it.',
        'referral.accepted' => 'The receiving team accepted a referral. Client contact details are now included for them.',
        'referral.declined' => 'The receiving team declined, with its reason.',
        'referral.expired' => 'The receiving team didn’t respond before the deadline.',
        'referral.passed_to_backup' => 'After a decline or missed deadline, the referral went to the city’s backup team.',
        'referral.stage_changed' => 'The receiving team moved the referral to another pipeline stage (including won and lost).',
        'referral.note_added' => 'A progress update was posted.',
        'referral.call_logged' => 'The receiving team logged a call with the client.',
        'referral.client_confirmed' => 'The client confirmed they agreed to be contacted.',
        'referral.agreement_signed' => 'One side signed the referral agreement.',
        'referral.fee_agreed' => 'Both sides agreed the referral fee.',
        'referral.close_confirmed' => 'The sender confirmed the close (or it was confirmed automatically).',
        'referral.close_disputed' => 'The sender disputed a reported close.',
        'referral.close_reopened' => 'A disputed close was reopened by the network owner.',
        'lead.received' => 'A verified lead arrived from your public profile.',
    ];

    /** Timeline entry => public event type. Entries not listed (reminders, internal checks) don’t raise webhooks. */
    public const FROM_TIMELINE = [
        'sent' => 'referral.sent', 'accepted' => 'referral.accepted', 'declined' => 'referral.declined', 'expired' => 'referral.expired',
        'passed_to_backup' => 'referral.passed_to_backup', 'stage' => 'referral.stage_changed', 'update' => 'referral.note_added', 'call_logged' => 'referral.call_logged',
        'client_confirmed' => 'referral.client_confirmed', 'signed' => 'referral.agreement_signed', 'fee_agreed' => 'referral.fee_agreed',
        'close_confirmed' => 'referral.close_confirmed', 'close_auto_confirmed' => 'referral.close_confirmed', 'close_upheld' => 'referral.close_confirmed',
        'close_disputed' => 'referral.close_disputed', 'close_reopened' => 'referral.close_reopened',
    ];

    /** Sent only by "Send test event"; can't be subscribed to. */
    public const PING = 'ping';
}
