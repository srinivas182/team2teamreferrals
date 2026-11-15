<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Calendar;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The private calendar link (an .ics address any calendar app can subscribe to). The token is random, stored as a
 * hash for lookup and encrypted so the link can be shown again; making a new link stops the old one.
 */
final class CalendarFeed
{
    public static function url(User $user): ?string
    {
        // Read from the database: the signed-in user model may have been loaded before the link was made.
        $token = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('calendar_feed_token');

        return is_string($token) ? url('/calendar/'.Crypt::decryptString($token).'.ics') : null;
    }

    public static function create(User $user): string
    {
        $token = Str::random(40);
        $user->forceFill(['calendar_feed_hash' => hash('sha256', $token), 'calendar_feed_token' => Crypt::encryptString($token)])->save();

        return url('/calendar/'.$token.'.ics');
    }

    public static function remove(User $user): void
    {
        $user->forceFill(['calendar_feed_hash' => null, 'calendar_feed_token' => null])->save();
    }

    public static function userFor(string $token): ?User
    {
        return User::query()->where('calendar_feed_hash', hash('sha256', $token))->where('is_active', true)->first();
    }

    /** The calendar as iCalendar text: times in UTC, which every calendar shows in its owner's own zone. */
    public static function ics(User $user): string
    {
        $esc = fn (?string $s) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $s);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Team to Team Referrals//Calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:Team to Team Referrals', 'X-PUBLISHED-TTL:PT1H', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H'];
        foreach (CalendarItems::for($user) as $i) {
            $when = $i['all_day']
                ? ['DTSTART;VALUE=DATE:'.$i['start']->format('Ymd'), 'DTEND;VALUE=DATE:'.$i['end']->format('Ymd')]
                : ['DTSTART:'.$i['start']->utc()->format('Ymd\THis\Z'), 'DTEND:'.$i['end']->utc()->format('Ymd\THis\Z')];
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:'.str_replace(':', '-', $i['key']).'-'.$user->id.'@teamtoteamreferrals.com', 'DTSTAMP:'.gmdate('Ymd\THis\Z'), ...$when,
                'SUMMARY:'.$esc($i['title']), 'DESCRIPTION:'.$esc($i['description']), ...($i['location'] ? ['LOCATION:'.$esc($i['location'])] : []), 'URL:'.$i['url'], 'END:VEVENT'];
        }
        $lines[] = 'END:VCALENDAR';

        // Lines longer than 75 octets are folded, as the standard asks.
        return implode("\r\n", array_map(fn ($l) => self::fold($l), $lines))."\r\n";
    }

    private static function fold(string $line): string
    {
        // 75 octets on the first line; continuation lines start with a space, so 74 more each.
        $out = '';
        $max = 75;
        while (strlen($line) > $max) {
            $cut = mb_strcut($line, 0, $max);
            $out .= $cut."\r\n ";
            $line = substr($line, strlen($cut));
            $max = 74;
        }

        return $out.$line;
    }
}
