<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Content\Demo\DemoWorkspace;
use App\Domains\Platform\Console\LoadTestSeed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The activation funnel (the simplification sprint): how far each new team has got, from signing up to its first
 * accepted referral, how long each step takes, and which teams are stuck. A team counts once, by its leader.
 *
 * Steps: signed up → verified (email and phone) → holds a seat → sent a first referral → first referral accepted
 * (one it sent, or one it received).
 */
final class Activation
{
    public const STEPS = ['signed_up' => 'Signed up', 'verified' => 'Verified email and phone', 'seat' => 'Holds a seat', 'sent' => 'Sent a first referral', 'accepted' => 'First referral accepted'];

    /** A team is stuck when it has been on the same step this long. */
    public const STUCK_AFTER_DAYS = 7;

    /**
     * Teams that signed up in the period (optionally in one network), each with when it reached every step.
     *
     * @return list<array{team: int, name: string, leader: string, email: string, steps: array<string, string|null>}>
     */
    public static function teams(int $days, ?int $groupId = null): array
    {
        $since = now()->subDays($days);
        $q = DB::table('teams as t')->join('users as u', 'u.id', '=', 't.leader_id')
            ->whereNull('t.sandbox_of_team_id')
            ->where('u.email', 'not like', '%@'.DemoWorkspace::DOMAIN)->where('u.email', 'not like', '%@'.LoadTestSeed::DOMAIN)
            // Staff don't count; members have the member role, so only the staff roles are left out.
            ->whereNotExists(fn ($x) => $x->from('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereColumn('model_has_roles.model_id', 'u.id')
                ->where('model_has_roles.model_type', \App\Domains\Identity\Models\User::class)->whereIn('roles.name', [\App\Domains\Identity\Authorization\Permissions::SUPER_ADMIN, \App\Domains\Identity\Authorization\Permissions::STAFF]))
            ->where('t.created_at', '>=', $since);
        if ($groupId !== null) {
            $q->whereExists(fn ($x) => $x->from('seat_applications')->whereColumn('seat_applications.team_id', 't.id')->where('seat_applications.group_id', $groupId));
        }
        $teams = $q->orderByDesc('t.created_at')->limit(2000)->get(['t.id', 't.name', 't.created_at', 'u.first_name', 'u.last_name', 'u.email', 'u.email_verified_at', 'u.phone_verified_at']);
        $ids = $teams->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        // One query per step for every team at once.
        $seat = DB::table('seat_applications')->whereIn('team_id', $ids)->where('status', 'approved')->groupBy('team_id')->selectRaw('team_id, min(coalesce(decided_at, updated_at)) as at')->pluck('at', 'team_id');
        $sent = DB::table('referrals')->whereIn('sender_team_id', $ids)->groupBy('sender_team_id')->selectRaw('sender_team_id as team_id, min(created_at) as at')->pluck('at', 'team_id');
        $acceptedOut = DB::table('referrals')->whereIn('sender_team_id', $ids)->whereNotNull('accepted_at')->groupBy('sender_team_id')->selectRaw('sender_team_id as team_id, min(accepted_at) as at')->pluck('at', 'team_id');
        $acceptedIn = DB::table('referrals')->whereIn('receiver_team_id', $ids)->whereNotNull('accepted_at')->groupBy('receiver_team_id')->selectRaw('receiver_team_id as team_id, min(accepted_at) as at')->pluck('at', 'team_id');

        return array_values($teams->map(function ($t) use ($seat, $sent, $acceptedOut, $acceptedIn) {
            $verified = $t->email_verified_at !== null && $t->phone_verified_at !== null ? max((string) $t->email_verified_at, (string) $t->phone_verified_at) : null;
            $accepted = collect([$acceptedOut[$t->id] ?? null, $acceptedIn[$t->id] ?? null])->filter()->min();

            return ['team' => (int) $t->id, 'name' => (string) $t->name, 'leader' => trim($t->first_name.' '.$t->last_name), 'email' => (string) $t->email, 'steps' => [
                'signed_up' => (string) $t->created_at, 'verified' => $verified, 'seat' => isset($seat[$t->id]) ? (string) $seat[$t->id] : null,
                'sent' => isset($sent[$t->id]) ? (string) $sent[$t->id] : null, 'accepted' => $accepted !== null ? (string) $accepted : null,
            ]];
        })->all());
    }

    /**
     * The funnel: how many teams reached each step, the share of all sign-ups, and the median time from signing up.
     *
     * @param  list<array{steps: array<string, string|null>}>  $teams
     * @return list<array{key: string, label: string, teams: int, share: float, medianHours: float|null}>
     */
    public static function funnel(array $teams): array
    {
        $total = max(1, count($teams));
        $out = [];
        foreach (self::STEPS as $key => $label) {
            $reached = array_values(array_filter($teams, fn ($t) => $t['steps'][$key] !== null));
            $hours = array_map(fn ($t) => max(0, (strtotime((string) $t['steps'][$key]) - strtotime((string) $t['steps']['signed_up'])) / 3600), $reached);
            sort($hours);
            $out[] = ['key' => $key, 'label' => $label, 'teams' => count($reached), 'share' => round(count($reached) / $total, 3),
                'medianHours' => $key === 'signed_up' || $hours === [] ? null : round($hours[intdiv(count($hours), 2)], 1)];
        }

        return $out;
    }

    /**
     * Teams that have been on the same step for a week or more, longest first, with the step they're on.
     *
     * @param  list<array{team: int, name: string, leader: string, email: string, steps: array<string, string|null>}>  $teams
     * @return list<array{team: int, name: string, leader: string, email: string, step: string, next: string, days: int}>
     */
    public static function stuck(array $teams): array
    {
        $keys = array_keys(self::STEPS);
        $out = [];
        foreach ($teams as $t) {
            $last = null;
            foreach ($keys as $i => $k) {
                if ($t['steps'][$k] === null) {
                    break;
                }
                $last = $i;
            }
            if ($last === null || $last === count($keys) - 1) {
                continue; // finished
            }
            $days = (int) Carbon::parse((string) $t['steps'][$keys[$last]])->diffInDays(now());
            if ($days >= self::STUCK_AFTER_DAYS) {
                $out[] = ['team' => $t['team'], 'name' => $t['name'], 'leader' => $t['leader'], 'email' => $t['email'],
                    'step' => self::STEPS[$keys[$last]], 'next' => self::STEPS[$keys[$last + 1]], 'days' => $days];
            }
        }
        usort($out, fn ($a, $b) => $b['days'] <=> $a['days']);

        return $out;
    }
}
