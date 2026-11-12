<?php

declare(strict_types=1);

namespace App\Domains\Community\Events;

use App\Domains\Community\Feed;
use App\Domains\Community\FeedChanged;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Network events: owners create them (one-off or recurring); members RSVP; "going" gets a calendar invite;
 * updates and cancellations revise it; reminders arrive a day and an hour before, in each member's time zone.
 * In-person events can have a capacity (with a waitlist) and QR check-in; afterwards there are notes and
 * "who you met" so attendees can follow up with referrals.
 */
final class Events
{
    public const VIDEO_LINK_MINUTES_BEFORE = 15;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  array{title: string, description: ?string, kind: string, location: ?string, video_url: ?string, starts_at: string, minutes: int, timezone: string, capacity: ?int, repeat: ?string, count: ?int}  $d
     */
    public function create(Group $group, User $owner, array $d): int
    {
        abort_unless($group->isOwnedBy($owner) || $owner->isStaff(), 403);
        $start = CarbonImmutable::parse($d['starts_at'], $d['timezone'])->utc();
        $dates = [$start];
        // Recurring: weekly, or the same weekday of the month ("first Tuesday"), each date with its own RSVPs.
        for ($i = 1; $d['repeat'] && $i < max(1, min(12, (int) ($d['count'] ?? 6))); $i++) {
            $local = $start->setTimezone($d['timezone']);
            $next = $d['repeat'] === 'weekly' ? $local->addWeeks($i) : self::nthWeekday($local, $i);
            $dates[] = $next->utc();
        }
        $first = null;
        foreach ($dates as $when) {
            $id = DB::table('events')->insertGetId(['group_id' => $group->id, 'created_by' => $owner->id, 'series_id' => $first, 'title' => $d['title'], 'description' => $d['description'],
                'kind' => $d['kind'], 'location' => $d['kind'] === 'in_person' ? $d['location'] : null, 'video_url' => $d['kind'] === 'virtual' ? $d['video_url'] : null,
                'starts_at' => $when, 'ends_at' => $when->addMinutes($d['minutes']), 'timezone' => $d['timezone'], 'capacity' => $d['kind'] === 'in_person' ? $d['capacity'] : null,
                'check_in_code' => strtolower(Str::random(12)), 'created_at' => now(), 'updated_at' => now()]);
            $first ??= $id;
        }
        // One feed post for the event (or the first of the series), with one-tap RSVP.
        $post = DB::table('feed_posts')->insertGetId(['group_id' => $group->id, 'team_id' => Team::of($owner)?->id, 'user_id' => $owner->id, 'kind' => 'event', 'event_id' => $first,
            'body' => Feed::clean($d['title'].($d['description'] ? "\n\n".$d['description'] : '')), 'created_at' => now(), 'updated_at' => now()]);
        FeedChanged::dispatch($group->slug, $post);

        return (int) $first;
    }

    /** Going / maybe / not going; going over capacity joins the waitlist; a freed place goes to the next person. */
    public function rsvp(int $eventId, User $user, string $status): string
    {
        $e = $this->event($eventId);
        abort_unless(Feed::member($user, Group::query()->findOrFail((int) $e->group_id)), 403);
        abort_if($e->cancelled_at !== null || CarbonImmutable::parse((string) $e->ends_at)->isPast(), 422, 'This event is no longer open.');
        $was = DB::table('event_rsvps')->where(['event_id' => $eventId, 'user_id' => $user->id])->value('status');
        if ($status === 'going' && $e->capacity !== null && $was !== 'going' && $this->goingCount($eventId) >= (int) $e->capacity) {
            $status = 'waitlist';
        }
        DB::table('event_rsvps')->updateOrInsert(['event_id' => $eventId, 'user_id' => $user->id], ['team_id' => Team::of($user)?->id, 'status' => $status, 'updated_at' => now(), 'created_at' => now()]);
        if ($status === 'going' && $was !== 'going') {
            $this->invite($e, $user, false);
        }
        if ($was === 'going' && $status !== 'going') {
            $this->promoteWaitlist($e);
        }

        return $status;
    }

    /** Owner edits: everyone going or maybe gets the revised invite (same UID, higher SEQUENCE). */
    public function update(int $eventId, User $owner, string $title, ?string $description, ?string $startsAt, ?int $minutes, ?string $location, ?string $videoUrl): void
    {
        $e = $this->ownEvent($eventId, $owner);
        $start = $startsAt ? CarbonImmutable::parse($startsAt, (string) $e->timezone)->utc() : CarbonImmutable::parse((string) $e->starts_at, 'UTC');
        $length = $minutes ?? CarbonImmutable::parse((string) $e->starts_at, 'UTC')->diffInMinutes(CarbonImmutable::parse((string) $e->ends_at, 'UTC'));
        DB::table('events')->where('id', $eventId)->update(['title' => $title, 'description' => $description, 'starts_at' => $start, 'ends_at' => $start->addMinutes((int) $length),
            'location' => $location ?? $e->location, 'video_url' => $videoUrl ?? $e->video_url, 'sequence' => (int) $e->sequence + 1, 'updated_at' => now()]);
        DB::table('event_rsvps')->where('event_id', $eventId)->update(['reminded_day_at' => null, 'reminded_hour_at' => null]);
        $e = $this->event($eventId);
        foreach ($this->attendees($eventId, ['going', 'maybe']) as $u) {
            $this->invite($e, $u, false, updated: true);
        }
    }

    public function cancel(int $eventId, User $owner): void
    {
        $e = $this->ownEvent($eventId, $owner);
        DB::table('events')->where('id', $eventId)->update(['cancelled_at' => now(), 'sequence' => (int) $e->sequence + 1]);
        $e = $this->event($eventId);
        foreach ($this->attendees($eventId, ['going', 'maybe', 'waitlist']) as $u) {
            $this->invite($e, $u, true);
        }
    }

    /** Every 15 minutes: a reminder a day before and an hour before, each in the member's own time zone. */
    public function reminders(): void
    {
        if (! app(\App\Domains\Platform\Modules\Modules::class)->enabled('events')) {
            return;
        }
        foreach (['day' => [23 * 60, 24 * 60 + 15], 'hour' => [45, 75]] as $which => [$from, $to]) {
            $events = DB::table('events')->whereNull('cancelled_at')->whereBetween('starts_at', [now()->addMinutes($from), now()->addMinutes($to)])->get();
            foreach ($events as $e) {
                $col = "reminded_{$which}_at";
                foreach (DB::table('event_rsvps')->where(['event_id' => $e->id, 'status' => 'going'])->whereNull($col)->get() as $r) {
                    $u = User::query()->find((int) $r->user_id);
                    if ($u === null) {
                        continue;
                    }
                    DB::table('event_rsvps')->where('id', $r->id)->update([$col => now()]);
                    $this->notifier->send($u, 'event.reminder', ['headline' => ($which === 'day' ? 'Tomorrow: ' : 'In an hour: ').$e->title,
                        'body' => 'Starts '.self::localTime((string) $e->starts_at, $u->timezone).'.'.($e->kind === 'virtual' ? ' The video link opens on the event page shortly before.' : ' At '.$e->location.'.')], url('/app/events/'.$e->id));
                }
            }
        }
    }

    /** QR check-in at an in-person event: the attendee list then shows who actually came. */
    public function checkIn(int $eventId, string $code, User $user): void
    {
        $e = $this->event($eventId);
        abort_unless(hash_equals((string) $e->check_in_code, strtolower($code)), 404);
        abort_unless(Feed::member($user, Group::query()->findOrFail((int) $e->group_id)), 403);
        $starts = CarbonImmutable::parse((string) $e->starts_at, 'UTC');
        abort_unless(now()->between($starts->subHour(), CarbonImmutable::parse((string) $e->ends_at, 'UTC')->addHour()), 422, 'Check-in opens an hour before the event.');
        DB::table('event_rsvps')->updateOrInsert(['event_id' => $eventId, 'user_id' => $user->id], ['team_id' => Team::of($user)?->id, 'status' => 'going', 'checked_in_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function notes(int $eventId, User $owner, string $notes): void
    {
        $this->ownEvent($eventId, $owner);
        DB::table('events')->where('id', $eventId)->update(['notes' => Feed::clean($notes), 'updated_at' => now()]);
    }

    /** The viewer may see the video link only if going, from shortly before the start until the end. */
    public static function canSeeVideo(stdClass $e, User $user): bool
    {
        $start = CarbonImmutable::parse((string) $e->starts_at, 'UTC');

        return $e->kind === 'virtual' && $e->cancelled_at === null && now()->between($start->subMinutes(self::VIDEO_LINK_MINUTES_BEFORE), CarbonImmutable::parse((string) $e->ends_at, 'UTC'))
            && DB::table('event_rsvps')->where(['event_id' => $e->id, 'user_id' => $user->id, 'status' => 'going'])->exists();
    }

    public static function localTime(string $utc, string $tz): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone($tz)->format('D j M, g:i A T');
    }

    public function goingCount(int $eventId): int
    {
        return DB::table('event_rsvps')->where(['event_id' => $eventId, 'status' => 'going'])->count();
    }

    private function promoteWaitlist(stdClass $e): void
    {
        $next = DB::table('event_rsvps')->where(['event_id' => $e->id, 'status' => 'waitlist'])->orderBy('created_at')->orderBy('id')->first();
        if ($next !== null && ($u = User::query()->find((int) $next->user_id)) !== null) {
            DB::table('event_rsvps')->where('id', $next->id)->update(['status' => 'going', 'updated_at' => now()]);
            $this->invite($e, $u, false, promoted: true);
        }
    }

    private function invite(stdClass $e, User $u, bool $cancel, bool $updated = false, bool $promoted = false): void
    {
        $when = self::localTime((string) $e->starts_at, $u->timezone);
        [$subject, $line] = match (true) {
            $cancel => ["Cancelled: {$e->title}", "{$e->title} on {$when} has been cancelled. Your calendar will update."],
            $updated => ["Updated: {$e->title}", "{$e->title} has changed: it’s now {$when}. Your calendar will update."],
            $promoted => ["A place opened up: {$e->title}", "You’re in! {$e->title}, {$when}."],
            default => ["You’re going: {$e->title}", "{$e->title}, {$when}. Add it to your calendar with the attached invite."],
        };
        $u->notify(new EventInvite($subject, $line, Ics::for($e, $cancel), url('/app/events/'.$e->id), $cancel));
        if ($promoted || $cancel || $updated) {
            $this->notifier->send($u, 'event.update', ['headline' => $subject, 'body' => $line], url('/app/events/'.$e->id));
        }
    }

    /**
     * @param  list<string>  $statuses
     * @return list<User>
     */
    private function attendees(int $eventId, array $statuses): array
    {
        return array_values(User::query()->whereIn('id', DB::table('event_rsvps')->where('event_id', $eventId)->whereIn('status', $statuses)->pluck('user_id'))->get()->all());
    }

    private function event(int $id): stdClass
    {
        $e = DB::table('events')->where('id', $id)->first();
        abort_if($e === null, 404);

        return $e;
    }

    private function ownEvent(int $id, User $owner): stdClass
    {
        $e = $this->event($id);
        $group = Group::query()->findOrFail((int) $e->group_id);
        abort_unless($group->isOwnedBy($owner) || $owner->isStaff(), 403);

        return $e;
    }

    /** The same weekday-of-month, $n months later ("first Tuesday" stays a first Tuesday; a 5th becomes the last). */
    private static function nthWeekday(CarbonImmutable $local, int $n): CarbonImmutable
    {
        $nth = (int) ceil($local->day / 7);
        $month = $local->startOfMonth()->addMonthsNoOverflow($n);
        $first = $month->dayOfWeek === $local->dayOfWeek ? $month : $month->next($local->dayOfWeek);
        $target = $first->addWeeks($nth - 1);
        if ($target->month !== $month->month) {
            $target = $target->subWeek();
        }

        return $target->setTime($local->hour, $local->minute);
    }
}
