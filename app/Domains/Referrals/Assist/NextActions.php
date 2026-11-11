<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Assist;

use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Suggested next actions for a referral, from plain rules (no AI, no credits): answer before the deadline, contact the
 * client within the network's window, update the referring team before the update is due, and move stalled deals.
 * Dismissed and snoozed suggestions stay hidden; a daily cap keeps the list short.
 */
final class NextActions
{
    public const DAILY_CAP = 10;

    public const STALLED_DAYS = 14;

    /** @return list<array{key: string, title: string, body: string, urgency: int, action: string, label: string}> */
    public static function for(Referral $ref, Team $team): array
    {
        $ref->loadMissing(['group', 'sender']);
        $now = CarbonImmutable::now();
        $out = [];
        $receiving = $team->id === $ref->receiver_team_id;
        $url = '/app/referrals/'.$ref->ulid;
        if ($receiving && $ref->status === 'sent') {
            $hours = max(0, (int) $now->diffInHours($ref->respond_by, false));
            $out[] = ['key' => 'respond', 'title' => 'Accept or decline', 'body' => "Answer within {$hours} hours to keep your response score up.", 'urgency' => 100 - min(90, $hours), 'action' => $url, 'label' => 'Open'];
        }
        if ($receiving && $ref->status === 'accepted' && $ref->close_status === null && ! in_array($ref->stage, ['won', 'lost'], true)) {
            $window = (int) $ref->group->rule('first_contact_hours');
            if ($ref->first_contact_at === null) {
                $due = CarbonImmutable::parse($ref->accepted_at ?? $ref->created_at)->addHours($window);
                $late = $due->isPast();
                $out[] = ['key' => 'first_contact', 'title' => $late ? 'First contact is overdue' : 'Contact the client', 'body' => ($ref->temperature === 'hot' ? 'Hot lead: ' : '').($late ? 'call today and log it.' : 'call by '.$due->format('D g:i A').' and log the call.'),
                    'urgency' => $late ? 95 : ($ref->temperature === 'hot' ? 85 : 70), 'action' => $url.'#log-call', 'label' => 'Log a call'];
            }
            $updateDays = (int) $ref->group->rule('update_days');
            $last = CarbonImmutable::parse($ref->last_update_at ?? $ref->accepted_at ?? $ref->created_at);
            $due = $last->addDays($updateDays);
            if ($now->diffInDays($due, false) <= 1) {
                $out[] = ['key' => 'update', 'title' => $due->isPast() ? 'Update overdue' : 'Update '.$ref->sender->name, 'body' => $due->isPast() ? 'The referring team is waiting: send a short update now.' : 'An update is due by '.$due->format('D j M').'.',
                    'urgency' => $due->isPast() ? 90 : 60, 'action' => $url.'#messages', 'label' => 'Draft an update'];
            }
            $since = CarbonImmutable::parse($ref->stage_changed_at ?? $ref->accepted_at ?? $ref->created_at);
            if ($ref->first_contact_at !== null && $since->diffInDays($now) >= self::STALLED_DAYS) {
                $out[] = ['key' => 'stalled', 'title' => 'No progress for '.(int) $since->diffInDays($now).' days', 'body' => 'Check in with the client, then move the stage or mark it lost.', 'urgency' => 50, 'action' => $url.'#pipeline', 'label' => 'Check in'];
            }
        }
        $hidden = DB::table('ai_suggestions')->where(['team_id' => $team->id, 'referral_id' => $ref->id, 'kind' => 'next_action'])
            ->where(fn ($q) => $q->where('status', 'dismissed')->orWhere(fn ($w) => $w->where('status', 'snoozed')->where('snoozed_until', '>', $now)))->pluck('key')->all();
        $out = array_values(array_filter($out, fn ($a) => ! in_array($a['key'], $hidden, true)));
        usort($out, fn ($a, $b) => $b['urgency'] <=> $a['urgency']);

        return $out;
    }

    /**
     * The team's most urgent actions across its referrals, capped per day so the list stays useful.
     *
     * @return list<array{key: string, title: string, body: string, urgency: int, action: string, label: string, referral: string}>
     */
    public static function forTeam(Team $team): array
    {
        $all = [];
        foreach (Referral::query()->where('receiver_team_id', $team->id)->whereIn('status', ['sent', 'accepted'])->whereNull('close_status')->with(['group', 'sender'])->limit(200)->get() as $ref) {
            foreach (self::for($ref, $team) as $a) {
                $all[] = [...$a, 'referral' => $ref->ulid];
            }
        }
        usort($all, fn ($a, $b) => $b['urgency'] <=> $a['urgency']);

        return array_slice($all, 0, self::DAILY_CAP);
    }
}
