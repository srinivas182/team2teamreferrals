<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A member's channel choices per notification type, and their quiet hours. */
final class Preferences
{
    /** @return array<string, array<string, bool>> type => channel => enabled, with defaults filled in */
    public function matrix(User $user): array
    {
        $saved = DB::table('notification_preferences')->where('user_id', $user->id)->get(['type', 'channel', 'enabled']);
        $out = [];
        foreach (NotificationCatalogue::TYPES as $type => $def) {
            foreach ($def['channels'] as $channel) {
                $row = $saved->first(fn ($r) => $r->type === $type && $r->channel === $channel);
                $out[$type][$channel] = $def['locked'] ? true : ($row === null ? true : (bool) $row->enabled);
            }
        }

        return $out;
    }

    public function allows(User $user, string $type, string $channel): bool
    {
        $def = NotificationCatalogue::get($type);
        if (! in_array($channel, $def['channels'], true)) {
            return false;
        }
        if ($def['locked']) {
            return true;
        }
        $row = DB::table('notification_preferences')->where(['user_id' => $user->id, 'type' => $type, 'channel' => $channel])->value('enabled');

        return $row === null ? true : (bool) $row;
    }

    /** Saves only changeable channels; locked types are ignored whatever is submitted. */
    public function set(User $user, string $type, string $channel, bool $enabled): void
    {
        $def = NotificationCatalogue::TYPES[$type] ?? null;
        if ($def === null || $def['locked'] || ! in_array($channel, $def['channels'], true)) {
            return;
        }
        DB::table('notification_preferences')->updateOrInsert(['user_id' => $user->id, 'type' => $type, 'channel' => $channel], ['enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** When quiet hours end next, or null if the member is not in quiet hours right now. */
    public static function quietUntil(User $user, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if ($user->quiet_hours_start === null || $user->quiet_hours_end === null) {
            return null;
        }
        $local = ($now ?? CarbonImmutable::now())->setTimezone($user->timezone);
        $start = $local->setTimeFromTimeString($user->quiet_hours_start);
        $end = $local->setTimeFromTimeString($user->quiet_hours_end);
        if ($start->lessThan($end)) { // e.g. 13:00–14:00
            return $local->betweenIncluded($start, $end->subSecond()) ? $end->utc() : null;
        }
        // Overnight window, e.g. 21:00–07:00.
        if ($local->greaterThanOrEqualTo($start)) {
            return $end->addDay()->utc();
        }

        return $local->lessThan($end) ? $end->utc() : null;
    }

    /** The next 7:00 in the member's own time zone, for the daily digest. */
    public static function nextDigest(User $user, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($user->timezone);
        $seven = $local->setTime(7, 0);

        return ($local->lessThan($seven) ? $seven : $seven->addDay())->utc();
    }
}
