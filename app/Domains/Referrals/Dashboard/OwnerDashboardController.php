<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The network owner's dashboard: numbers with comparison, leaders, teams to check in with, breaches; drill-down and CSV. */
final class OwnerDashboardController
{
    public const CHECKIN_DAYS = 7;

    public function page(Request $request, Group $group): Response
    {
        $this->authorise($request, $group);
        $p = $this->period($request);

        return Inertia::render('app/group/Dashboard', [
            'group' => ['slug' => $group->slug, 'name' => $group->name, 'restricted' => $group->industry->tier !== Tier::Open],
            'groups' => $this->owned($this->user($request))->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->values(),
            'period' => $p->toArray(), 'presets' => Period::PRESETS,
            'cards' => OwnerKpis::cards($group, $p), 'leaders' => OwnerKpis::leaders($group, $p), 'checkIns' => OwnerKpis::lowPerformers($group, $p),
            'template' => 'Hi! Checking in from '.$group->name.': I noticed a few referral deadlines slipped recently. Is there anything we can help with, or should we route some referrals to your backup while you catch up?',
            'status' => session('status'),
            'seo' => SeoData::private($group->name.': dashboard')->toArray(),
        ]);
    }

    public function drill(Request $request, Group $group, string $kpi): Response
    {
        $this->authorise($request, $group);
        abort_unless(isset(OwnerKpis::KPIS[$kpi]), 404);
        $p = $this->period($request);

        return Inertia::render('app/DashboardDrill', [
            'kpi' => ['key' => $kpi, 'label' => OwnerKpis::KPIS[$kpi][0], 'kind' => OwnerKpis::KPIS[$kpi][1], 'records' => OwnerKpis::KPIS[$kpi][2], 'value' => OwnerKpis::value($kpi, $group, $p)],
            'rows' => OwnerKpis::rows($kpi, $group, $p), 'period' => $p->toArray(), 'base' => '/app/group/'.$group->slug.'/dashboard',
            'seo' => SeoData::private(OwnerKpis::KPIS[$kpi][0])->toArray(),
        ]);
    }

    public function csv(Request $request, Group $group, ?string $kpi = null): StreamedResponse
    {
        $this->authorise($request, $group);
        abort_if($kpi !== null && ! isset(OwnerKpis::KPIS[$kpi]), 404);
        $p = $this->period($request);

        return response()->streamDownload(function () use ($group, $kpi, $p) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            if ($kpi === null) {
                fputcsv($out, ['Measure', 'This period', 'Previous period', 'Change %']);
                foreach (OwnerKpis::cards($group, $p) as $c) {
                    fputcsv($out, [$c['label'], DashboardController::show($c['value'], $c['kind']), DashboardController::show($c['previous'], $c['kind']), $c['change'] ?? '']);
                }
            } else {
                $rows = OwnerKpis::rows($kpi, $group, $p, 10000);
                fputcsv($out, array_keys($rows[0] ?? ['id' => '', 'client' => '', 'with' => '', 'status' => '', 'date' => '']));
                foreach ($rows as $r) {
                    fputcsv($out, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_values($r)));
                }
            }
            fclose($out);
        }, $group->slug.'-'.($kpi ?? 'summary').'-'.$p->from->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /** One tap: a friendly check-in to a struggling team (editable), at most once a week per team. */
    public function checkIn(Request $request, Group $group, Notifier $notifier): RedirectResponse
    {
        $this->authorise($request, $group);
        $d = $request->validate(['team' => ['required', 'integer'], 'message' => ['required', 'string', 'min:10', 'max:1000']]);
        $team = Team::query()->with('leader')->findOrFail((int) $d['team']);
        abort_unless(DB::table('seats')->where(['group_id' => $group->id, 'primary_team_id' => $team->id])->exists(), 404);
        if (DB::table('owner_checkins')->where(['group_id' => $group->id, 'team_id' => $team->id])->where('created_at', '>=', now()->subDays(self::CHECKIN_DAYS))->exists()) {
            throw ValidationException::withMessages(['team' => 'You checked in with this team in the last week.']);
        }
        DB::table('owner_checkins')->insert(['group_id' => $group->id, 'team_id' => $team->id, 'owner_id' => $this->user($request)->id, 'message' => $d['message'], 'created_at' => now()]);
        $notifier->send($team->leader, 'network.checkin', ['headline' => $group->name.': a check-in from your network owner', 'body' => (string) $d['message']], url('/app/performance'));

        return back()->with('status', 'checkin-sent');
    }

    /** /app/owner/{page}: the owner's network page, or a choice when they own several. */
    public function shortcut(Request $request, ?string $page = null): Response|RedirectResponse
    {
        $map = ['dashboard' => '/dashboard', 'seat-map' => '/seat-map', 'applications' => '/applications', 'challenges' => '/challenges', 'reports' => '/reports', '' => ''];
        $page = (string) $page;
        abort_unless(array_key_exists($page, $map), 404);
        $groups = $this->owned($this->user($request));
        abort_if($groups->isEmpty(), 403);
        if ($groups->count() === 1 || $request->query('group')) {
            $g = $groups->firstWhere('slug', (string) $request->query('group')) ?? $groups->first();

            return redirect('/app/group/'.$g->slug.$map[$page]);
        }

        return Inertia::render('app/group/Choose', ['page' => $page, 'groups' => $groups->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->values(),
            'seo' => SeoData::private('Choose a network')->toArray()]);
    }

    /** @return \Illuminate\Support\Collection<int, Group> */
    private function owned(User $user): \Illuminate\Support\Collection
    {
        return Group::query()->with('industry')->whereIn('id', DB::table('group_owners')->where('user_id', $user->id)->pluck('group_id'))->orderBy('name')->get();
    }

    private function authorise(Request $request, Group $group): void
    {
        abort_unless($group->isOwnedBy($this->user($request)) || $this->user($request)->isStaff(), 403);
    }

    private function period(Request $request): Period
    {
        return Period::from((string) $request->query('preset'), (string) $request->query('from'), (string) $request->query('to'));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
