<?php

declare(strict_types=1);

namespace App\Domains\Network\Teams;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Visibility;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team performance, for leaders and assistants: how the team shares the referrals it is offered. Per person, over
 * the chosen period: referrals accepted, how fast, how many closed, the value closed, referrals sent and the fees
 * those earned.
 */
final class TeamPerformanceController
{
    public const PERIODS = [30, 90, 365];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $team = Team::of($user);
        abort_unless($team !== null && Visibility::seesAll($team, $user), 403, 'Team performance is for your team leader and assistants.');
        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 90;
        $since = now()->subDays($days);

        $offered = Referral::query()->with('group')->where('receiver_team_id', $team->id)->where('created_at', '>=', $since)->get();
        $accepted = $offered->filter(fn (Referral $r) => $r->accepted_at !== null && $r->status === 'accepted');
        $sent = Referral::query()->where('sender_team_id', $team->id)->where('created_at', '>=', $since)->get(['id', 'sender_user_id']);
        $fees = DB::table('referral_fees')->whereIn('referral_id', $sent->pluck('id'))->where('payee_team_id', $team->id)->whereIn('status', ['paid', 'received'])->pluck('amount_cents', 'referral_id');

        $people = $team->members()->orderBy('first_name')->get()->push($team->leader)->unique('id');
        $rows = $people->map(function (User $m) use ($team, $accepted, $sent, $fees) {
            $mine = $accepted->filter(fn (Referral $r) => (int) ($r->receiver_user_id ?? $team->leader_id) === $m->id);
            // Minutes from when the team was offered it (the response window started) to the accept.
            $waits = $mine->map(fn (Referral $r) => max(0, (int) $r->respond_by->copy()->subHours((int) $r->group->rule('accept_hours'))->diffInMinutes($r->accepted_at, false)))->values();
            $won = $mine->filter(fn (Referral $r) => $r->closing_value_cents !== null)->count();
            $closed = $mine->filter(fn (Referral $r) => $r->closed_at !== null)->count();
            $mySent = $sent->where('sender_user_id', $m->id);

            return [
                'name' => $m->name, 'role' => (string) ($team->roleOf($m) ?? 'leader'),
                'accepted' => $mine->count(),
                'medianMinutes' => $waits->isEmpty() ? null : (int) $waits->median(),
                'won' => $won,
                'conversion' => $closed > 0 ? (int) round($won / $closed * 100) : null,
                'open' => $mine->count() - $closed,
                'closedValueCents' => (int) $mine->sum(fn (Referral $r) => (int) $r->closing_value_cents),
                'sent' => $mySent->count(),
                'feesCents' => (int) $mySent->sum(fn ($r) => (int) ($fees[$r->id] ?? 0)),
            ];
        })->sortByDesc('accepted')->values();

        return Inertia::render('app/TeamPerformance', [
            'days' => $days, 'periods' => self::PERIODS, 'team' => $team->name,
            'totals' => [
                'offered' => $offered->count(),
                'accepted' => $accepted->count(),
                'missed' => $offered->whereIn('status', ['expired', 'declined'])->count(),
                'waiting' => $offered->where('status', 'sent')->count(),
            ],
            'rows' => $rows,
            'seo' => SeoData::private('Team performance')->toArray(),
        ]);
    }
}
