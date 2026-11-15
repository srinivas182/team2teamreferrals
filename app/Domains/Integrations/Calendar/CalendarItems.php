<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Calendar;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What goes in a person's calendar (Sprint 45b), from 7 days ago to 120 days ahead:
 * - events: network events they said they're going to (or might), with the location or a link
 * - deadlines: for their team's referrals: respond by (received, not yet answered: the client is "Tom B."), first
 *   contact due and next update due (accepted and open)
 * - nurture: check-in days for future leads their team is nurturing
 * Each item has a stable key, so calendars update it in place when it changes and remove it when it's gone.
 */
final class CalendarItems
{
    public const KINDS = ['events' => 'Events you’re going to', 'deadlines' => 'Referral deadlines (respond, first contact, updates)', 'nurture' => 'Check-ins for future leads'];

    /**
     * @param  list<string>  $kinds
     * @return list<array{key: string, kind: string, title: string, description: string, start: CarbonImmutable, end: CarbonImmutable, all_day: bool, location: ?string, url: string}>
     */
    public static function for(User $user, array $kinds = ['events', 'deadlines', 'nurture']): array
    {
        $from = now()->subDays(7)->toImmutable();
        $to = now()->addDays(120)->toImmutable();
        $out = [];
        if (in_array('events', $kinds, true)) {
            $rows = DB::table('event_rsvps as r')->join('events as e', 'e.id', '=', 'r.event_id')->where('r.user_id', $user->id)->whereIn('r.status', ['going', 'maybe'])
                ->whereNull('e.cancelled_at')->whereBetween('e.starts_at', [$from, $to])->get(['e.*', 'r.status as rsvp']);
            foreach ($rows as $e) {
                $url = url('/app/events/'.$e->id);
                $out[] = ['key' => 'event:'.$e->id, 'kind' => 'events', 'title' => ($e->rsvp === 'maybe' ? '(Maybe) ' : '').$e->title,
                    'description' => trim(((string) $e->description)."\n\n".$url), 'start' => CarbonImmutable::parse((string) $e->starts_at, 'UTC'), 'end' => CarbonImmutable::parse((string) $e->ends_at, 'UTC'),
                    'all_day' => false, 'location' => $e->kind === 'virtual' ? 'Online: '.($e->video_url ?: $url) : $e->location, 'url' => $url];
            }
        }
        $team = Team::of($user);
        if ($team === null) {
            return $out;
        }
        $refs = fn () => Referral::query()->with(['group', 'sender'])->where('receiver_team_id', $team->id)->whereHas('group', fn ($q) => $q->where('is_sandbox', false));
        if (in_array('deadlines', $kinds, true)) {
            foreach ($refs()->where('status', 'sent')->whereBetween('respond_by', [$from, $to])->get() as $r) {
                $out[] = self::deadline($r, 'respond', "Respond to the referral for {$r->shortClientName()}", "{$r->sender->name} sent your team a referral in {$r->group->name}. Accept or decline before this time.", $r->respond_by->toImmutable());
            }
            foreach ($refs()->where('status', 'accepted')->whereNull('closed_at')->get() as $r) {
                if ($r->accepted_at === null) {
                    continue;
                }
                if ($r->first_contact_at === null) {
                    $due = $r->accepted_at->toImmutable()->addHours((int) $r->group->rule('first_contact_hours'));
                    $out[] = self::deadline($r, 'contact', "First contact due: {$r->client_name}", "Contact {$r->client_name} and log it on the referral.", $due);
                } else {
                    $due = ($r->last_update_at ?? $r->first_contact_at)->toImmutable()->addDays((int) $r->group->rule('update_days'));
                    $out[] = self::deadline($r, 'update', "Update due: {$r->client_name}", "Post an update or move the stage so {$r->sender->name} stays in the loop.", $due);
                }
            }
        }
        if (in_array('nurture', $kinds, true)) {
            foreach ($refs()->where('status', 'accepted')->whereNull('closed_at')->whereNotNull('nurture_next_at')->get() as $r) {
                $day = CarbonImmutable::parse((string) $r->nurture_next_at, 'UTC')->startOfDay();
                if ($day->between($from, $to)) {
                    $url = url('/app/referrals/'.$r->ulid);
                    $out[] = ['key' => 'nurture:'.$r->ulid, 'kind' => 'nurture', 'title' => "Check in with {$r->client_name}", 'description' => "A future lead from {$r->sender->name}.\n\n{$url}",
                        'start' => $day, 'end' => $day->addDay(), 'all_day' => true, 'location' => null, 'url' => $url];
                }
            }
        }

        return array_values(array_filter($out, fn ($i) => $i['start']->between($from, $to)));
    }

    /**
     * A hash of what an item says, to skip writing it again when nothing changed.
     *
     * @param  array{title: string, description: string, start: CarbonImmutable, end: CarbonImmutable, all_day: bool, location: ?string}  $item
     */
    public static function hash(array $item): string
    {
        return sha1(json_encode([$item['title'], $item['description'], $item['start']->toIso8601String(), $item['end']->toIso8601String(), $item['all_day'], $item['location']]) ?: '');
    }

    /** @return array{key: string, kind: string, title: string, description: string, start: CarbonImmutable, end: CarbonImmutable, all_day: bool, location: ?string, url: string} */
    private static function deadline(Referral $r, string $what, string $title, string $text, CarbonImmutable $due): array
    {
        $url = url('/app/referrals/'.$r->ulid);

        // A 15-minute block ending at the deadline.
        return ['key' => $what.':'.$r->ulid, 'kind' => 'deadlines', 'title' => $title, 'description' => $text."\n\n".$url, 'start' => $due->subMinutes(15), 'end' => $due, 'all_day' => false, 'location' => null, 'url' => $url];
    }
}
