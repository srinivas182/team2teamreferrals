<?php

declare(strict_types=1);

namespace App\Domains\Community\Events;

use stdClass;

/**
 * Calendar invites (.ics). Times are written in UTC, which every calendar converts to its owner's own time zone;
 * the same UID with a higher SEQUENCE updates an invite in place, and METHOD:CANCEL removes it.
 */
final class Ics
{
    public static function for(stdClass $e, bool $cancel = false): string
    {
        $fmt = fn (string $t) => gmdate('Ymd\THis\Z', (int) strtotime($t.' UTC'));
        $esc = fn (?string $s) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $s);
        $where = $e->kind === 'virtual' ? 'Online (link on the event page)' : (string) $e->location;
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Team to Team Referrals//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:'.($cancel ? 'CANCEL' : 'REQUEST'),
            'BEGIN:VEVENT', 'UID:event-'.$e->id.'@teamtoteamreferrals.com', 'SEQUENCE:'.(int) $e->sequence, 'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART:'.$fmt((string) $e->starts_at), 'DTEND:'.$fmt((string) $e->ends_at), 'SUMMARY:'.$esc((string) $e->title),
            'DESCRIPTION:'.$esc(trim(($e->description ?? '')."\n\n".url('/app/events/'.$e->id))), 'LOCATION:'.$esc($where), 'URL:'.url('/app/events/'.$e->id),
            'STATUS:'.($cancel ? 'CANCELLED' : 'CONFIRMED'), 'END:VEVENT', 'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(fn ($l) => mb_strcut($l, 0, 998), $lines))."\r\n";
    }
}
