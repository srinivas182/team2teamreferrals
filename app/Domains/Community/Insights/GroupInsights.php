<?php

declare(strict_types=1);

namespace App\Domains\Community\Insights;

use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Platform\Ai\Prompts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Weekly insights for network owners, from the last 30 days: weak cities (referrals that couldn't be placed, teams
 * waiting for a seat), slow teams (slow to accept, missed updates) and rising members (more accepted referrals than
 * the 30 days before). The summary is AI-written on the platform's account (free for owners) and falls back to plain
 * wording from the same figures when AI is unavailable.
 */
final class GroupInsights
{
    public function __construct(private readonly AiProvider $ai, private readonly Notifier $notifier) {}

    /** @return array{weakCities: list<array{city: string, unplaced: int, waiting: int}>, slowTeams: list<array{team: string, reason: string}>, rising: list<array{team: string, now: int, before: int}>} */
    public static function figures(Group $group, ?CarbonImmutable $at = null): array
    {
        $now = $at ?? CarbonImmutable::now();
        $since = $now->subDays(30);
        $unplaced = DB::table('referrals')->join('seats', 'seats.id', '=', 'referrals.seat_id')->where('referrals.group_id', $group->id)
            ->whereIn('referrals.status', ['declined', 'expired'])->where('referrals.updated_at', '>=', $since)
            ->groupBy('seats.territory_name')->selectRaw('seats.territory_name as city, count(*) as n')->pluck('n', 'city');
        $waiting = DB::table('seat_waitlist')->join('seats', 'seats.id', '=', 'seat_waitlist.seat_id')->where('seats.group_id', $group->id)
            ->groupBy('seats.territory_name')->selectRaw('seats.territory_name as city, count(*) as n')->pluck('n', 'city');
        $weak = collect($unplaced->keys())->merge($waiting->keys())->unique()
            ->map(fn ($c) => ['city' => (string) $c, 'unplaced' => (int) ($unplaced[$c] ?? 0), 'waiting' => (int) ($waiting[$c] ?? 0)])
            ->sortByDesc(fn ($c) => $c['unplaced'] * 2 + $c['waiting'])->take(3)->values()->all();
        $weak = array_values($weak);
        $window = (int) $group->rule('accept_hours') * 60;
        $slow = [];
        $rising = [];
        foreach (Team::query()->whereIn('id', DB::table('seats')->where('group_id', $group->id)->whereNotNull('primary_team_id')->select('primary_team_id'))->get() as $t) {
            $accepted = DB::table('referrals')->where(['group_id' => $group->id, 'receiver_team_id' => $t->id])->whereNotNull('accepted_at')->where('created_at', '>=', $since)->get(['id', 'created_at', 'accepted_at']);
            $mins = $accepted->map(fn ($r) => CarbonImmutable::parse((string) $r->created_at)->diffInMinutes(CarbonImmutable::parse((string) $r->accepted_at)))->sort()->values();
            $missed = DB::table('referral_events')->whereIn('referral_id', $accepted->pluck('id'))->where(['event' => 'missed_update', 'team_id' => $t->id])->count();
            if ($mins->count() >= 2 && $mins[intdiv($mins->count(), 2)] > $window / 2) {
                $slow[] = ['team' => $t->name, 'reason' => 'typically accepts in '.round($mins[intdiv($mins->count(), 2)] / 60, 1).' hours'];
            } elseif ($missed >= 2) {
                $slow[] = ['team' => $t->name, 'reason' => "{$missed} missed updates"];
            }
            $count = fn (CarbonImmutable $from, CarbonImmutable $to) => DB::table('referrals')->where('group_id', $group->id)
                ->where(fn ($q) => $q->where('receiver_team_id', $t->id)->orWhere('sender_team_id', $t->id))->whereNotNull('accepted_at')->whereBetween('accepted_at', [$from, $to])->count();
            [$nowN, $before] = [$count($since, $now), $count($since->subDays(30), $since)];
            if ($nowN - $before >= 2) {
                $rising[] = ['team' => $t->name, 'now' => $nowN, 'before' => $before];
            }
        }
        usort($rising, fn ($a, $b) => ($b['now'] - $b['before']) <=> ($a['now'] - $a['before']));

        return ['weakCities' => $weak, 'slowTeams' => array_slice($slow, 0, 3), 'rising' => array_slice($rising, 0, 3)];
    }

    /** Mondays: compute, summarise and send to each network's owners (once per week). */
    public function run(): void
    {
        $week = now()->startOfWeek()->toDateString();
        foreach (Group::query()->with(['owners', 'industry'])->get() as $group) {
            if ($group->owners->isEmpty() || DB::table('group_insights')->where(['group_id' => $group->id, 'week' => $week])->exists()) {
                continue;
            }
            $f = self::figures($group);
            [$summary, $ai] = $this->summary($group, $f);
            DB::table('group_insights')->insert(['group_id' => $group->id, 'week' => $week, 'data' => json_encode($f), 'summary' => $summary, 'ai_written' => $ai, 'created_at' => now()]);
            foreach ($group->owners as $owner) {
                $this->notifier->send($owner, 'network.insights', ['headline' => $group->name.': this week’s insights', 'body' => $summary], url('/app/group/'.$group->slug));
            }
        }
    }

    /**
     * @param  array{weakCities: list<array{city: string, unplaced: int, waiting: int}>, slowTeams: list<array{team: string, reason: string}>, rising: list<array{team: string, now: int, before: int}>}  $f
     * @return array{0: string, 1: bool}
     */
    public function summary(Group $group, array $f): array
    {
        $plain = self::plain($f);
        // The figures always go out; the AI-written summary only with "AI owner insights" on.
        if (! $this->ai->enabled() || ! app(\App\Domains\Platform\Modules\Modules::class)->enabled('ai_insights')) {
            return [$plain, false];
        }
        try {
            $p = Prompts::active('owner_insights');
            $vars = ['network' => $group->name, 'figures' => $plain];
            $text = trim($this->ai->complete(Prompts::fill($p['template'], $vars), 'owner_insights', 250, Prompts::fill($p['system'], $vars))['text']);

            return $text !== '' ? [$text, true] : [$plain, false];
        } catch (Throwable) {
            return [$plain, false];
        }
    }

    /** @param array{weakCities: list<array{city: string, unplaced: int, waiting: int}>, slowTeams: list<array{team: string, reason: string}>, rising: list<array{team: string, now: int, before: int}>} $f */
    public static function plain(array $f): string
    {
        $parts = [];
        if ($f['weakCities'] !== []) {
            $parts[] = 'Cities to strengthen: '.implode('; ', array_map(fn ($c) => $c['city'].' ('.implode(', ', array_filter([
                $c['unplaced'] ? $c['unplaced'].' unplaced' : null, $c['waiting'] ? $c['waiting'].' waiting for a seat' : null])).')', $f['weakCities'])).'.';
        }
        if ($f['slowTeams'] !== []) {
            $parts[] = 'Teams to check in with: '.implode('; ', array_map(fn ($t) => $t['team'].' ('.$t['reason'].')', $f['slowTeams'])).'.';
        }
        if ($f['rising'] !== []) {
            $parts[] = 'Rising: '.implode('; ', array_map(fn ($t) => $t['team'].' ('.$t['before'].' → '.$t['now'].' referrals)', $f['rising'])).'.';
        }

        return $parts === [] ? 'A steady week: no cities, teams or trends need your attention.' : implode(' ', $parts);
    }
}
