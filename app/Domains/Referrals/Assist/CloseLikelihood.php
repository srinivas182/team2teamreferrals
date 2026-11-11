<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Assist;

use App\Domains\Referrals\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How likely a referral is to close, for the receiving team only. It starts from how often referrals close in this
 * network and adjusts for what is happening on this one; every adjustment is a stated reason. It uses only referral
 * and process data, never anything about the client personally, and nothing else in the platform (seat challenges,
 * rankings) reads it.
 */
final class CloseLikelihood
{
    public const PRIOR_RATE = 0.30;

    public const PRIOR_WEIGHT = 20;

    /** @return array{score: int, reasons: list<array{text: string, effect: int}>}|null */
    public static function for(Referral $ref): ?array
    {
        if ($ref->status !== 'accepted' || $ref->close_status !== null || in_array($ref->stage, ['won', 'lost'], true)) {
            return null;
        }
        $ref->loadMissing('group');
        // The network's close rate, blended with a prior so a new network isn't scored on two deals.
        $closed = DB::table('referrals')->where('group_id', $ref->group_id)->where(fn ($q) => $q->whereIn('close_status', ['confirmed', 'auto_confirmed'])->orWhere('stage', 'lost'))->get(['close_status']);
        $won = $closed->whereIn('close_status', ['confirmed', 'auto_confirmed'])->count();
        $base = ($won + self::PRIOR_RATE * self::PRIOR_WEIGHT) / ($closed->count() + self::PRIOR_WEIGHT);
        $score = $base * 100;
        $reasons = [['text' => 'Referrals in this network close about '.(int) round($base * 100).'% of the time', 'effect' => 0]];
        $add = function (int $effect, string $text) use (&$score, &$reasons): void {
            $score += $effect;
            $reasons[] = ['text' => $text, 'effect' => $effect];
        };
        match ($ref->temperature) {
            'hot' => $add(15, 'Hot: the client wants to act within 30 days'),
            'warm' => $add(5, 'Warm: acting in the next few months'),
            'future' => $add(-15, 'Future lead: no near-term plans'),
            default => null,
        };
        $now = CarbonImmutable::now();
        if ($ref->accepted_at !== null && CarbonImmutable::parse($ref->created_at)->diffInMinutes(CarbonImmutable::parse($ref->accepted_at)) <= 120) {
            $add(5, 'Accepted quickly');
        }
        $window = (int) $ref->group->rule('first_contact_hours');
        if ($ref->first_contact_at !== null) {
            $onTime = $ref->accepted_at === null || CarbonImmutable::parse($ref->first_contact_at)->lte(CarbonImmutable::parse($ref->accepted_at)->addHours($window));
            $onTime ? $add(10, 'First contact made on time') : $add(-5, 'First contact was late');
        } elseif ($ref->accepted_at !== null && CarbonImmutable::parse($ref->accepted_at)->addHours($window)->isPast()) {
            $add(-15, 'The client hasn’t been contacted yet');
        }
        $last = CarbonImmutable::parse($ref->last_update_at ?? $ref->accepted_at ?? $ref->created_at);
        $stale = (int) $last->diffInDays($now);
        $stale > (int) $ref->group->rule('update_days') * 2 ? $add(-10, "No update in {$stale} days") : null;
        $stages = array_values(array_filter(array_column(app(\App\Domains\Referrals\Pipeline::class)->stages($ref), 'key'), fn ($k) => ! in_array($k, ['won', 'lost'], true)));
        $at = array_search($ref->stage, $stages, true);
        if ($at !== false && count($stages) > 1 && $at > 0) {
            $add((int) round(20 * $at / (count($stages) - 1)), 'Moved forward to “'.str_replace('_', ' ', (string) $ref->stage).'”');
        }
        $idle = (int) CarbonImmutable::parse($ref->stage_changed_at ?? $ref->accepted_at ?? $ref->created_at)->diffInDays($now);
        $idle >= 60 ? $add(-10, "Same stage for {$idle} days") : null;
        $score = max(1, min(99, (int) round($score)));
        $top = collect($reasons)->slice(1)->sortByDesc(fn ($r) => abs($r['effect']))->take(3)->values()->all();
        $result = ['score' => $score, 'reasons' => [$reasons[0], ...$top]];
        DB::table('referral_scores')->updateOrInsert(['referral_id' => $ref->id], ['score' => $score, 'reasons' => json_encode($result['reasons']), 'computed_at' => now()]);

        return $result;
    }
}
