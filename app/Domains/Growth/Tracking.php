<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use Illuminate\Support\Facades\DB;

/**
 * Campaign tracking: a signed click link (so it can't be turned into an open redirect), an open pixel, and a reply
 * address. Opens are a weak signal (Apple Mail loads every image), so clicks and replies are what count.
 */
final class Tracking
{
    public static function clickUrl(string $sendUlid, string $to): string
    {
        $to = str_starts_with($to, '/') ? url($to) : $to;

        return url('/g/c/'.$sendUlid).'?'.http_build_query(['u' => $to, 's' => self::sign($sendUlid, $to)]);
    }

    public static function openUrl(string $sendUlid): string
    {
        return url('/g/o/'.$sendUlid.'.gif');
    }

    /** reply+g<send>@<inbound domain>, when reply-by-email is set up; otherwise replies go to the sending address. */
    public static function replyAddress(string $sendUlid): ?string
    {
        $domain = (string) config('mail.inbound.domain');

        return $domain !== '' ? 'reply+g'.$sendUlid.'@'.$domain : null;
    }

    public static function valid(string $sendUlid, string $to, string $sig): bool
    {
        return hash_equals(self::sign($sendUlid, $to), $sig);
    }

    /** The first click on an email: recorded, scored, and remembered for sign-up attribution. */
    public static function click(string $sendUlid): ?int
    {
        $send = DB::table('campaign_sends')->where('ulid', $sendUlid)->first();
        if ($send === null) {
            return null;
        }
        if ($send->clicked_at === null) {
            DB::table('campaign_sends')->where('id', $send->id)->update(['clicked_at' => now(), 'opened_at' => $send->opened_at ?? now(), 'updated_at' => now()]);
            $p = Prospect::query()->find((int) $send->prospect_id);
            if ($p !== null) {
                Prospects::log($p, null, 'email_click', 'Clicked “'.$send->subject.'”.');
                Prospects::rescore($p);
            }
        }

        return (int) $send->campaign_id;
    }

    public static function open(string $sendUlid): void
    {
        $send = DB::table('campaign_sends')->where('ulid', $sendUlid)->whereNull('opened_at')->first();
        if ($send !== null) {
            DB::table('campaign_sends')->where('id', $send->id)->update(['opened_at' => now(), 'updated_at' => now()]);
            $p = Prospect::query()->find((int) $send->prospect_id);
            if ($p !== null) {
                Prospects::log($p, null, 'email_open', 'Opened “'.$send->subject.'”.');
                Prospects::rescore($p);
            }
        }
    }

    private static function sign(string $sendUlid, string $to): string
    {
        return substr(hash_hmac('sha256', $sendUlid.'|'.$to, (string) config('app.key')), 0, 32);
    }
}
