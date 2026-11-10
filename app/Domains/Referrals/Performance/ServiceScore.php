<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Performance;

use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The service score, out of 100, over a rolling 90 days, in four equal, visible parts. No black box: members see
 * each part, the numbers behind it and what would help most. Fewer than five referrals means no score yet (a quiet
 * period never costs anyone), and referrals that arrived during approved time off don't count.
 */
final class ServiceScore
{
    public const WINDOW_DAYS = 90;

    public const MIN_REFERRALS = 5;

    public const PARTS = [
        'answered' => ['label' => 'Answered in time', 'tip' => 'Accept or decline new referrals before the deadline. One-tap accept from the email or text makes this quick.'],
        'contacted' => ['label' => 'First contact on time', 'tip' => 'Call the client soon after accepting and press “Log a call”.'],
        'updated' => ['label' => 'Kept the sender updated', 'tip' => 'Post a progress note or move the stage at least every update period.'],
        'closed' => ['label' => 'Closed the loop', 'tip' => 'Move each referral along, and mark it Won or Lost when it’s decided.'],
    ];

    /**
     * @return array{score: ?int, referrals: int, parts: array<string, array{label: string, tip: string, points: ?int, done: int, of: int}>, from: string, to: string}
     */
    public static function for(Team $team, ?CarbonImmutable $asOf = null): array
    {
        $to = $asOf ?? CarbonImmutable::now();
        $from = $to->subDays(self::WINDOW_DAYS);
        $off = DB::table('team_time_off')->where('team_id', $team->id)->get(['starts_on', 'ends_on']);
        // Referrals this team was asked to answer: it holds them now, or they were offered to it (incl. as backup).
        // (The "sent" event belongs to the sending team, so it is deliberately not used here.)
        $offered = DB::table('referral_events')->where('team_id', $team->id)->whereIn('event', ['accepted', 'declined', 'expired', 'passed_to_backup'])->pluck('referral_id');
        $refs = Referral::query()->with('group')->where('created_at', '>=', $from)->where('created_at', '<=', $to)
            ->where(fn ($q) => $q->where('receiver_team_id', $team->id)->orWhereIn('id', $offered))
            ->where('sender_team_id', '!=', $team->id)
            ->get()
            // Time off doesn't count against anyone.
            ->reject(fn (Referral $r) => $off->contains(fn ($o) => $r->created_at->toDateString() >= $o->starts_on && $r->created_at->toDateString() <= $o->ends_on));
        $events = DB::table('referral_events')->whereIn('referral_id', $refs->pluck('id'))->where('team_id', $team->id)->get(['referral_id', 'event'])->groupBy('referral_id');
        $ev = fn (Referral $r, string $e) => ($events[$r->id] ?? collect())->contains('event', $e);

        $answered = $refs->filter(fn (Referral $r) => $ev($r, 'accepted') || $ev($r, 'declined'));
        $accepted = $refs->filter(fn (Referral $r) => $ev($r, 'accepted') && $r->receiver_team_id === $team->id);
        $contactHours = fn (Referral $r) => (int) $r->group->rule('first_contact_hours');
        $contactedOnTime = $accepted->filter(fn (Referral $r) => $r->first_contact_at !== null && $r->accepted_at !== null && $r->first_contact_at->lte($r->accepted_at->copy()->addHours($contactHours($r))));
        $updated = $accepted->reject(fn (Referral $r) => $ev($r, 'missed_update'));
        $moved = $accepted->filter(fn (Referral $r) => $r->closed_at !== null || $ev($r, 'stage'));

        // A part with nothing to count yet has no points and is left out of the score (never free marks).
        $part = fn (string $k, int $done, int $of) => [...self::PARTS[$k], 'points' => $of === 0 ? null : (int) round(25 * $done / $of), 'done' => $done, 'of' => $of];
        $parts = [
            'answered' => $part('answered', $answered->count(), $refs->count()),
            'contacted' => $part('contacted', $contactedOnTime->count(), $accepted->count()),
            'updated' => $part('updated', $updated->count(), $accepted->count()),
            'closed' => $part('closed', $moved->count(), $accepted->count()),
        ];

        $counted = array_filter($parts, fn ($p) => $p['points'] !== null);

        return [
            // Scaled over the parts that have something to count, so 0 of 5 answered is 0, not 75.
            'score' => $refs->count() < self::MIN_REFERRALS || $counted === [] ? null : (int) round(100 * array_sum(array_column($counted, 'points')) / (25 * count($counted))),
            'referrals' => $refs->count(), 'parts' => $parts, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
        ];
    }

    /**
     * The one or two changes that would lift the score most.
     *
     * @param  array{parts: array<string, array{label: string, tip: string, points: ?int, done: int, of: int}>}  $result
     * @return list<string>
     */
    public static function topTips(array $result): array
    {
        $parts = $result['parts'];
        $parts = array_filter($parts, fn ($p) => $p['points'] !== null && $p['points'] < 25);
        uasort($parts, fn ($a, $b) => $a['points'] <=> $b['points']);

        return array_values(array_map(fn ($p) => $p['tip'], array_slice($parts, 0, 2)));
    }

    public static function onTimeOff(Team $team, ?CarbonImmutable $day = null): bool
    {
        $d = ($day ?? CarbonImmutable::now())->toDateString();

        return DB::table('team_time_off')->where('team_id', $team->id)->where('starts_on', '<=', $d)->where('ends_on', '>=', $d)->exists();
    }
}
