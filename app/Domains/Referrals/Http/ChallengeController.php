<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Performance\SeatChallenges;
use App\Domains\Referrals\Performance\ServiceScore;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ChallengeController
{
    public function __construct(private readonly SeatChallenges $challenges) {}

    /** @return array<int, array<string, mixed>> challenges involving the person's team, for the Performance page */
    public static function forTeam(Team $team): array
    {
        return DB::table('seat_challenges as c')->join('seats as s', 's.id', '=', 'c.seat_id')->join('teams as h', 'h.id', '=', 'c.holder_team_id')->join('teams as x', 'x.id', '=', 'c.challenger_team_id')
            ->where(fn ($q) => $q->where('c.holder_team_id', $team->id)->orWhere('c.challenger_team_id', $team->id))->whereIn('c.status', ['open', 'pending', 'appealed'])
            ->get(['c.*', 's.territory_name', 'h.name as holder', 'x.name as challenger'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'status' => (string) $c->status, 'territory' => (string) $c->territory_name, 'holder' => (string) $c->holder, 'challenger' => (string) $c->challenger,
                'isHolder' => (int) $c->holder_team_id === $team->id, 'threshold' => (int) $c->threshold, 'scoreAtOpen' => (int) $c->score_at_open,
                'windowEnds' => (string) $c->window_ends_at, 'appealUntil' => $c->appeal_until, 'choice' => (string) $c->holder_choice])->values()->all();
    }

    public function appeal(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000'], 'choice' => ['required', 'in:backup,waitlist,leave']]);
        $this->challenges->appeal($id, $this->user($request), (string) $d['reason'], (string) $d['choice']);

        return back()->with('status', 'challenge-appealed');
    }

    public function choose(Request $request, int $id): RedirectResponse
    {
        $this->challenges->choose($id, $this->user($request), (string) $request->validate(['choice' => ['required', 'in:backup,waitlist,leave']])['choice']);

        return back()->with('status', 'challenge-choice');
    }

    /** Group owners and admins: seats at risk, challenges, appeals and outcomes. */
    public function index(Request $request, Group $group): Response
    {
        $user = $this->owner($request, $group);
        $seats = Seat::query()->with('primary')->where('group_id', $group->id)->whereNotNull('below_since')->get();

        return Inertia::render('app/group/Challenges', [
            'group' => ['slug' => $group->slug, 'name' => $group->name, 'threshold' => (int) $group->rule('challenge_threshold'), 'days' => (int) $group->rule('challenge_days')],
            'atRisk' => $seats->map(fn (Seat $s) => ['territory' => $s->territory_name, 'holder' => (string) $s->primary?->name, 'score' => $s->primary ? ServiceScore::for($s->primary)['score'] : null,
                'daysBelow' => (int) $s->below_since?->diffInDays(now())])->values(),
            'challenges' => DB::table('seat_challenges as c')->join('seats as s', 's.id', '=', 'c.seat_id')->join('teams as h', 'h.id', '=', 'c.holder_team_id')->join('teams as x', 'x.id', '=', 'c.challenger_team_id')
                ->where('s.group_id', $group->id)->orderByDesc('c.id')->limit(100)->get(['c.*', 's.territory_name', 'h.name as holder', 'x.name as challenger'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'status' => (string) $c->status, 'territory' => (string) $c->territory_name, 'holder' => (string) $c->holder, 'challenger' => (string) $c->challenger,
                    'scoreAtOpen' => (int) $c->score_at_open, 'scoreAtEnd' => $c->score_at_end, 'windowEnds' => (string) $c->window_ends_at, 'appeal' => $c->appeal_reason, 'note' => $c->decision_note]),
            'isAdmin' => $user->can('groups.manage'),
            'status' => session('status'),
            'seo' => SeoData::private('Challenges · '.$group->name)->toArray(),
        ]);
    }

    public function decide(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['uphold' => ['required', 'boolean'], 'note' => ['required', 'string', 'max:1000']], ['note.required' => 'Explain the decision; both teams see it.']);
        $this->owner($request, $this->groupOf($id));
        $this->challenges->decide($id, $this->user($request), (bool) $d['uphold'], (string) $d['note']);

        return back()->with('status', $d['uphold'] ? 'challenge-upheld' : 'challenge-overturned');
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        abort_unless($this->user($request)->can('groups.manage'), 403);
        $this->challenges->cancel($id, $this->user($request), (string) $request->validate(['note' => ['required', 'string', 'max:1000']])['note']);

        return back()->with('status', 'challenge-cancelled');
    }

    private function groupOf(int $id): Group
    {
        return Group::query()->findOrFail((int) DB::table('seat_challenges as c')->join('seats as s', 's.id', '=', 'c.seat_id')->where('c.id', $id)->value('s.group_id'));
    }

    private function owner(Request $request, Group $group): User
    {
        $u = $this->user($request);
        abort_unless($group->isOwnedBy($u) || $u->can('groups.manage'), 403);

        return $u;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
