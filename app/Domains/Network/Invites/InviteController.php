<?php

declare(strict_types=1);

namespace App\Domains\Network\Invites;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class InviteController
{
    /** /join/{code}: remember who invited this visitor, then sign up. */
    public function join(Request $request, string $code): RedirectResponse
    {
        $team = Team::query()->where('invite_code', strtolower($code))->first();
        if ($team !== null) {
            $request->session()->put('invite_code', $team->invite_code);
            $request->session()->put('invited_by', $team->name);
        }

        return redirect('/register');
    }

    /** Portal → Invites: your link, who joined through it, and the network leaderboard. */
    public function show(Request $request): Response
    {
        /** @var User $u */
        $u = $request->user();
        $team = Team::of($u) ?? Team::forLeader($u);
        $groupIds = DB::table('seats')->where('primary_team_id', $team->id)->pluck('group_id');
        $qualified = ['rewarded', 'badge', 'capped'];

        return Inertia::render('app/Invites', [
            'link' => url('/join/'.$team->invite_code),
            'signups' => DB::table('invite_signups as s')->join('users as u', 'u.id', '=', 's.user_id')->leftJoin('teams as t', 't.leader_id', '=', 'u.id')
                ->where('s.inviter_team_id', $team->id)->orderByDesc('s.id')->get(['s.status', 's.reward_cents', 's.created_at', 't.name', 'u.first_name'])
                ->map(fn ($r) => ['name' => (string) ($r->name ?: $r->first_name), 'status' => (string) $r->status, 'reward' => $r->reward_cents / 100, 'joined' => substr((string) $r->created_at, 0, 10)]),
            'leaderboard' => DB::table('invite_signups as s')->join('teams as t', 't.id', '=', 's.inviter_team_id')
                ->whereIn('s.inviter_team_id', DB::table('seats')->whereIn('group_id', $groupIds)->whereNotNull('primary_team_id')->select('primary_team_id'))
                ->whereIn('s.status', $qualified)->where('s.qualified_at', '>=', now()->startOfYear())
                ->groupBy('t.id', 't.name')->selectRaw('t.name, count(*) as n')->orderByDesc('n')->limit(10)->get()
                ->map(fn ($r) => ['team' => (string) $r->name, 'invites' => (int) $r->n, 'me' => $r->name === $team->name]),
            'rules' => ['months' => InviteRewards::setting('reward_months'), 'cap' => InviteRewards::setting('cap')],
            'seo' => SeoData::private('Invites')->toArray(),
        ]);
    }
}
