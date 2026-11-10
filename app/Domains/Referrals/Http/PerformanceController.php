<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Performance\ServiceScore;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Portal → Performance: the service score with every part shown, report cards, and approved time off. */
final class PerformanceController
{
    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? Team::forLeader($user);
        $score = ServiceScore::for($team);

        return Inertia::render('app/Performance', [
            'score' => $score, 'tips' => ServiceScore::topTips($score), 'minReferrals' => ServiceScore::MIN_REFERRALS, 'windowDays' => ServiceScore::WINDOW_DAYS,
            'cards' => DB::table('report_cards')->where('team_id', $team->id)->orderByDesc('period')->limit(12)->get()
                ->map(fn ($c) => ['period' => (string) $c->period, 'score' => $c->score, 'data' => json_decode((string) $c->data, true)]),
            'timeOff' => DB::table('team_time_off')->where('team_id', $team->id)->where('ends_on', '>=', now()->toDateString())->orderBy('starts_on')->get(['id', 'starts_on', 'ends_on', 'reason'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'from' => (string) $t->starts_on, 'to' => (string) $t->ends_on, 'reason' => $t->reason]),
            'canEdit' => in_array($team->roleOf($user), ['leader', 'assistant'], true),
            'status' => session('status'),
            'seo' => SeoData::private('Performance')->toArray(),
        ]);
    }

    public function addTimeOff(Request $request): RedirectResponse
    {
        $team = $this->editable($request);
        $d = $request->validate(['from' => ['required', 'date', 'after_or_equal:today'], 'to' => ['required', 'date', 'after_or_equal:from'], 'reason' => ['nullable', 'string', 'max:160']]);
        if (\Carbon\Carbon::parse((string) $d['from'])->diffInDays(\Carbon\Carbon::parse((string) $d['to'])) > 30) {
            throw ValidationException::withMessages(['to' => 'Time off can be up to 31 days at a time. For longer breaks, contact your group owner.']);
        }
        DB::table('team_time_off')->insert(['team_id' => $team->id, 'starts_on' => $d['from'], 'ends_on' => $d['to'], 'reason' => $d['reason'] ?? null, 'created_by' => $this->user($request)->id, 'created_at' => now(), 'updated_at' => now()]);
        activity('team')->causedBy($request->user())->performedOn($team)->event('time-off')->withProperties($d)->log("Time off {$d['from']} to {$d['to']}");

        return back()->with('status', 'time-off-added');
    }

    public function removeTimeOff(Request $request, int $id): RedirectResponse
    {
        $team = $this->editable($request);
        DB::table('team_time_off')->where(['id' => $id, 'team_id' => $team->id])->delete();

        return back()->with('status', 'time-off-removed');
    }

    private function editable(Request $request): Team
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? Team::forLeader($user);
        abort_unless(in_array($team->roleOf($user), ['leader', 'assistant'], true), 403);

        return $team;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
