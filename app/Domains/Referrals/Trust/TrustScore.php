<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Trust;

use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The trust score shown publicly: five parts over the last 12 months, recent activity counting more (half weight
 * after six months). A part needs at least 3 items to count; the weights are shared among the parts that count, so
 * fee reliability simply drops out where there are no fees (Tier B, or no fees yet). Fewer than 3 accepted referrals
 * is "New". Seat challenges keep using the service score, which covers only what a team controls.
 */
final class TrustScore
{
    public const WINDOW_DAYS = 365;

    public const HALF_LIFE_DAYS = 180;

    public const MIN_ITEMS = 3;

    public const NEW_BELOW = 3;

    public const PARTS = [
        'response' => ['label' => 'Response speed', 'tip' => 'Accept or decline new referrals before the deadline.', 'weight' => 25],
        'updates' => ['label' => 'Update compliance', 'tip' => 'Update the referring team before each update is due.', 'weight' => 25],
        'conversion' => ['label' => 'Conversion', 'tip' => 'Follow up quickly and keep stalled referrals moving to win more of them.', 'weight' => 20],
        'fees' => ['label' => 'Fee reliability', 'tip' => 'Pay agreed referral fees by their due date.', 'weight' => 15],
        'accuracy' => ['label' => 'Outcome accuracy', 'tip' => 'Mark referrals won only once they have closed, with the right date and value.', 'weight' => 15],
    ];

    /** @return array<string, int> */
    public static function weights(): array
    {
        $saved = json_decode((string) app(Settings::class)->get('trust.weights', ''), true);

        return collect(self::PARTS)->map(fn ($p, $k) => is_array($saved) && isset($saved[$k]) ? max(0, (int) $saved[$k]) : $p['weight'])->all();
    }

    /**
     * @param  array<string, int>|null  $weights
     * @return array{score: ?int, status: string, parts: array<string, array{label: string, tip: string, points: ?int, items: int}>}
     */
    public static function compute(Team $team, ?CarbonImmutable $asOf = null, ?array $weights = null): array
    {
        $now = $asOf ?? CarbonImmutable::now();
        $from = $now->subDays(self::WINDOW_DAYS);
        $w = fn ($at) => 0.5 ** (CarbonImmutable::parse((string) $at)->diffInDays($now) / self::HALF_LIFE_DAYS);
        $received = DB::table('referrals')->where('receiver_team_id', $team->id)->where('created_at', '>=', $from)->where('created_at', '<=', $now)
            ->get(['id', 'status', 'created_at', 'respond_by', 'accepted_at', 'updated_at', 'stage', 'close_status']);
        $accepted = $received->whereNotNull('accepted_at');
        // Each part: [weighted good, weighted total, items]
        $part = fn () => [0.0, 0.0, 0];
        $p = ['response' => $part(), 'updates' => $part(), 'conversion' => $part(), 'fees' => $part(), 'accuracy' => $part()];
        $add = function (string $k, bool $good, $at) use (&$p, $w) {
            $x = $w($at);
            $p[$k] = [$p[$k][0] + ($good ? $x : 0), $p[$k][1] + $x, $p[$k][2] + 1];
        };
        $passed = DB::table('referral_events')->whereIn('referral_id', $received->pluck('id'))->where(['event' => 'passed_to_backup', 'team_id' => $team->id])->pluck('created_at', 'referral_id');
        $missed = DB::table('referral_events')->whereIn('referral_id', $accepted->pluck('id'))->where(['event' => 'missed_update', 'team_id' => $team->id])->pluck('referral_id')->flip();
        $disputed = DB::table('referral_events')->whereIn('referral_id', $accepted->pluck('id'))->where('event', 'close_disputed')->pluck('referral_id')->flip();
        foreach ($received as $r) {
            if ($r->status === 'sent') {
                continue; // still open: not yet late or on time
            }
            $answered = $r->accepted_at ?? ($passed[$r->id] ?? ($r->status === 'declined' ? $r->updated_at : null));
            $add('response', $answered !== null && CarbonImmutable::parse((string) $answered)->lte(CarbonImmutable::parse((string) $r->respond_by)), $r->created_at);
        }
        foreach ($accepted as $r) {
            $add('updates', ! isset($missed[$r->id]), $r->accepted_at);
            $won = in_array($r->close_status, ['confirmed', 'auto_confirmed'], true);
            if ($won || $r->stage === 'lost') {
                $add('conversion', $won, $r->updated_at);
            }
            if ($won || isset($disputed[$r->id])) {
                $add('accuracy', ! isset($disputed[$r->id]), $r->updated_at);
            }
        }
        foreach (DB::table('referral_fees')->where('payer_team_id', $team->id)->where('due_on', '>=', $from->toDateString())->where('due_on', '<', $now->toDateString())->get(['due_on', 'paid_at']) as $f) {
            $add('fees', $f->paid_at !== null && CarbonImmutable::parse((string) $f->paid_at)->lte(CarbonImmutable::parse((string) $f->due_on)->endOfDay()), $f->due_on);
        }
        $weights ??= self::weights();
        $parts = [];
        $sum = 0.0;
        $wsum = 0;
        foreach (self::PARTS as $k => $def) {
            [$good, $total, $items] = $p[$k];
            $points = $items >= self::MIN_ITEMS && $total > 0 ? (int) round(100 * $good / $total) : null;
            $parts[$k] = ['label' => $def['label'], 'tip' => $def['tip'], 'points' => $points, 'items' => $items];
            if ($points !== null && ($weights[$k] ?? 0) > 0) {
                $sum += $points * $weights[$k];
                $wsum += $weights[$k];
            }
        }
        $new = $accepted->count() < self::NEW_BELOW || $wsum === 0;

        return ['score' => $new ? null : (int) round($sum / $wsum), 'status' => $new ? 'new' : 'scored', 'parts' => $parts];
    }

    /** Nightly: every team with a seat (or any referral history) is recomputed and stored. */
    public static function recomputeAll(): int
    {
        $teams = Team::query()->whereIn('id', DB::table('seats')->whereNotNull('primary_team_id')->select('primary_team_id'))
            ->orWhereIn('id', DB::table('referrals')->select('receiver_team_id'))->get();
        foreach ($teams as $t) {
            $r = self::compute($t);
            DB::table('trust_scores')->updateOrInsert(['team_id' => $t->id], ['score' => $r['score'], 'status' => $r['status'], 'parts' => json_encode($r['parts']), 'computed_at' => now()]);
            if ($r['score'] !== null) {
                DB::table('trust_score_history')->updateOrInsert(['team_id' => $t->id, 'day' => now()->toDateString()], ['score' => $r['score']]);
            }
        }

        return $teams->count();
    }

    /** @return array{score: ?int, status: string, parts: array<string, mixed>}|null the stored (nightly) result */
    public static function stored(int $teamId): ?array
    {
        $row = DB::table('trust_scores')->where('team_id', $teamId)->first();

        return $row === null ? null : ['score' => $row->score !== null ? (int) $row->score : null, 'status' => (string) $row->status, 'parts' => (array) json_decode((string) $row->parts, true)];
    }

    /**
     * What a change of weights would do, using the parts already computed (nothing is saved).
     *
     * @param  array<string, int>  $weights
     * @return array{teams: int, up: int, down: int, averageChange: float, biggest: list<array{team: string, from: int, to: int}>}
     */
    public static function preview(array $weights): array
    {
        $changes = [];
        foreach (DB::table('trust_scores as s')->join('teams as t', 't.id', '=', 's.team_id')->where('s.status', 'scored')->get(['s.score', 's.parts', 't.name']) as $row) {
            $sum = 0.0;
            $wsum = 0;
            foreach ((array) json_decode((string) $row->parts, true) as $k => $part) {
                if (($part['points'] ?? null) !== null && ($weights[$k] ?? 0) > 0) {
                    $sum += $part['points'] * $weights[$k];
                    $wsum += $weights[$k];
                }
            }
            $new = $wsum > 0 ? (int) round($sum / $wsum) : (int) $row->score;
            $changes[] = ['team' => (string) $row->name, 'from' => (int) $row->score, 'to' => $new];
        }
        usort($changes, fn ($a, $b) => abs($b['to'] - $b['from']) <=> abs($a['to'] - $a['from']));

        return ['teams' => count($changes), 'up' => count(array_filter($changes, fn ($c) => $c['to'] > $c['from'])), 'down' => count(array_filter($changes, fn ($c) => $c['to'] < $c['from'])),
            'averageChange' => $changes === [] ? 0.0 : round(array_sum(array_map(fn ($c) => $c['to'] - $c['from'], $changes)) / count($changes), 1), 'biggest' => array_slice($changes, 0, 5)];
    }
}
