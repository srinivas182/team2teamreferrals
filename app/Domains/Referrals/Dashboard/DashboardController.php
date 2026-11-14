<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Dashboard;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Analytics\AnalyticsStore;
use App\Domains\Referrals\Assist\NextActions;
use App\Domains\Referrals\Credits;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Portal → Dashboard: the team's numbers with period comparison, charts, live action bar, drill-down, saved views and exports. */
final class DashboardController
{
    public function team(Request $request, AnalyticsStore $store): Response
    {
        [$user, $team, $p] = $this->context($request);
        $series = rescue(fn () => $store->daily('team', $team->id, $p->from->toDateString(), $p->to->toDateString(), ['referral.sent', 'referral.accepted', 'fee.received']), [], false);

        return Inertia::render('app/Dashboard', [
            'period' => $p->toArray(), 'presets' => Period::PRESETS,
            'cards' => TeamKpis::cards($team, $p),
            'series' => $this->series($series, $p),
            'actions' => NextActions::forTeam($team),
            'credits' => Credits::balance($team),
            'views' => $this->views($user, 'team'),
            'maps' => \App\Domains\Network\Directory\NetworkMap::forTeam($team),
            'status' => session('status'),
            'seo' => SeoData::private('Dashboard')->toArray(),
        ]);
    }

    /** The records behind one number. */
    public function drill(Request $request, string $kpi): Response
    {
        abort_unless(isset(TeamKpis::KPIS[$kpi]), 404);
        [, $team, $p] = $this->context($request);

        return Inertia::render('app/DashboardDrill', [
            'kpi' => ['key' => $kpi, 'label' => TeamKpis::KPIS[$kpi][0], 'kind' => TeamKpis::KPIS[$kpi][1], 'records' => TeamKpis::KPIS[$kpi][2], 'value' => TeamKpis::value($kpi, $team, $p)],
            'rows' => TeamKpis::rows($kpi, $team, $p), 'period' => $p->toArray(),
            'seo' => SeoData::private(TeamKpis::KPIS[$kpi][0])->toArray(),
        ]);
    }

    /** CSV: the summary, or one KPI's records. Short client names only. */
    public function csv(Request $request, ?string $kpi = null): StreamedResponse
    {
        [, $team, $p] = $this->context($request);
        abort_if($kpi !== null && ! isset(TeamKpis::KPIS[$kpi]), 404);
        $name = 'dashboard-'.($kpi ?? 'summary').'-'.$p->from->toDateString().'-to-'.$p->to->toDateString().'.csv';

        return response()->streamDownload(function () use ($kpi, $team, $p) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            if ($kpi === null) {
                fputcsv($out, ['Measure', 'This period', 'Previous period', 'Change %']);
                foreach (TeamKpis::cards($team, $p) as $c) {
                    fputcsv($out, [$c['label'], self::show($c['value'], $c['kind']), self::show($c['previous'], $c['kind']), $c['change'] ?? '']);
                }
            } else {
                $rows = TeamKpis::rows($kpi, $team, $p, 10000);
                fputcsv($out, array_keys($rows[0] ?? ['id' => '', 'client' => '', 'with' => '', 'status' => '', 'date' => '']));
                foreach ($rows as $r) {
                    fputcsv($out, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_values($r)));
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function pdf(Request $request): \Illuminate\Http\Response
    {
        [, $team, $p] = $this->context($request);
        $cards = array_map(fn ($c) => [...$c, 'shown' => self::show($c['value'], $c['kind']), 'before' => self::show($c['previous'], $c['kind'])], TeamKpis::cards($team, $p));

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.dashboard', ['team' => $team->name, 'period' => $p->toArray(), 'cards' => $cards])
            ->download('dashboard-'.$p->from->toDateString().'-to-'.$p->to->toDateString().'.pdf');
    }

    public function saveView(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:60'], 'preset' => ['required', 'in:'.implode(',', array_keys(Period::PRESETS))],
            'from' => ['nullable', 'required_if:preset,custom', 'date'], 'to' => ['nullable', 'required_if:preset,custom', 'date', 'after_or_equal:from'], 'default' => ['boolean']]);
        $user = $this->user($request);
        if ($d['default'] ?? false) {
            DB::table('dashboard_views')->where(['user_id' => $user->id, 'dashboard' => 'team'])->update(['is_default' => false]);
        }
        DB::table('dashboard_views')->insert(['user_id' => $user->id, 'dashboard' => 'team', 'name' => $d['name'], 'preset' => $d['preset'], 'from' => $d['from'] ?? null, 'to' => $d['to'] ?? null,
            'is_default' => (bool) ($d['default'] ?? false), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'view-saved');
    }

    public function deleteView(Request $request, int $view): RedirectResponse
    {
        DB::table('dashboard_views')->where(['id' => $view, 'user_id' => $this->user($request)->id])->delete();

        return back()->with('status', 'view-deleted');
    }

    /** @return array{0: User, 1: Team, 2: Period} the period from the query, else the member's default view, else the last 30 days */
    private function context(Request $request): array
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? Team::forLeader($user);
        if (! $request->query('preset') && ($v = DB::table('dashboard_views')->where(['user_id' => $user->id, 'dashboard' => 'team', 'is_default' => true])->first()) !== null) {
            return [$user, $team, Period::from((string) $v->preset, $v->from, $v->to)];
        }

        return [$user, $team, Period::from((string) $request->query('preset'), (string) $request->query('from'), (string) $request->query('to'))];
    }

    /** @return list<array{id: int, name: string, preset: string, from: ?string, to: ?string, default: bool}> */
    private function views(User $user, string $dashboard): array
    {
        return array_values(DB::table('dashboard_views')->where(['user_id' => $user->id, 'dashboard' => $dashboard])->orderBy('name')->get()
            ->map(fn ($v) => ['id' => (int) $v->id, 'name' => (string) $v->name, 'preset' => (string) $v->preset, 'from' => $v->from, 'to' => $v->to, 'default' => (bool) $v->is_default])->all());
    }

    /**
     * Daily series for the charts, with every day present (zeros included).
     *
     * @param  list<array{day: string, event_type: string, events: int, value: int, amount_cents: int}>  $rows
     * @return list<array{day: string, sent: int, accepted: int, fees: int}>
     */
    private function series(array $rows, Period $p): array
    {
        $by = collect($rows)->groupBy('day');
        $out = [];
        for ($d = $p->from; $d->lte($p->to); $d = $d->addDay()) {
            $day = $by->get($d->toDateString(), collect());
            $out[] = ['day' => $d->toDateString(), 'sent' => (int) $day->where('event_type', 'referral.sent')->sum('events'), 'accepted' => (int) $day->where('event_type', 'referral.accepted')->sum('events'),
                'fees' => (int) $day->where('event_type', 'fee.received')->sum('amount_cents')];
        }

        return $out;
    }

    public static function show(int|float|null $v, string $kind): string
    {
        return match (true) {
            $v === null => '',
            $kind === 'money' => '$'.number_format($v / 100, 2),
            $kind === 'percent' => $v.'%',
            $kind === 'minutes' => $v < 60 ? $v.' min' : round($v / 60, 1).' h',
            default => (string) $v,
        };
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
