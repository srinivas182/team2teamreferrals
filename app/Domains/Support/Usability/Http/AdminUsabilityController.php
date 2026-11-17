<?php

declare(strict_types=1);

namespace App\Domains\Support\Usability\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use App\Domains\Support\Usability\Usability;
use App\Domains\Support\Usability\UsabilityInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Usability tests: coverage per industry family, the fix list, testers and the scripts they get. */
final class AdminUsabilityController
{
    public function index(Request $request): Response
    {
        $family = (string) $request->query('family', 'property');
        if (! isset(Industry::FAMILIES[$family])) {
            $family = 'property';
        }
        $rank = "case severity when 'high' then 0 when 'medium' then 1 else 2 end";

        return Inertia::render('admin/usability/Index', [
            'coverage' => Usability::coverage(),
            'issues' => DB::table('usability_issues')->orderByRaw("case status when 'open' then 0 when 'planned' then 1 else 2 end")->orderByRaw($rank)->orderByDesc('hits')->limit(200)->get()
                ->map(fn ($i) => ['id' => (int) $i->id, 'title' => $i->title, 'kind' => $i->kind, 'path' => $i->path, 'severity' => $i->severity, 'status' => $i->status,
                    'hits' => (int) $i->hits, 'testers' => count((array) json_decode((string) $i->testers, true)),
                    'families' => array_map(fn ($f) => Industry::FAMILIES[$f] ?? $f, (array) json_decode((string) $i->families, true)),
                    'notes' => (string) $i->notes, 'lastSeen' => substr((string) $i->last_seen_at, 0, 10)])->values(),
            'sessions' => DB::table('usability_sessions')->orderByDesc('id')->limit(100)->get()
                ->map(function ($s) {
                    $results = (array) json_decode((string) ($s->results ?? '{}'), true);

                    return ['id' => $s->ulid, 'name' => $s->name, 'email' => $s->email, 'family' => Industry::FAMILIES[$s->family] ?? $s->family, 'status' => $s->status,
                        'tasks' => count(Usability::journeyKeys($s)), 'done' => count(array_filter($results, fn ($r) => ($r['outcome'] ?? '') === 'done')),
                        'gaveUp' => count(array_filter($results, fn ($r) => ($r['outcome'] ?? '') === 'gave_up')),
                        'problems' => DB::table('usability_events')->where('session_id', $s->id)->whereIn('kind', array_keys(Usability::PROBLEMS))->count(),
                        'invited' => substr((string) $s->invited_at, 0, 10)];
                })->values(),
            'families' => collect(Industry::FAMILIES)->map(fn ($name, $key) => ['key' => $key, 'name' => $name])->values(),
            'industries' => Industry::query()->where('family', $family)->orderBy('name')->get(['slug', 'name'])->map(fn ($i) => ['slug' => $i->slug, 'name' => $i->name])->values(),
            'journeys' => collect(Usability::JOURNEYS)->map(fn ($j, $k) => ['key' => $k, 'title' => str_replace(['{referral}', '{fee}'], ['referral', 'referral fee'], $j['title'])])->values(),
            'family' => $family,
            'script' => Usability::script($family, $request->query('industry') !== null ? (string) $request->query('industry') : null),
            'statuses' => Usability::STATUSES,
            'flash' => session('status'),
            'seo' => SeoData::private('Usability tests')->toArray(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'],
            'family' => ['required', Rule::in(array_keys(Industry::FAMILIES))], 'industry' => ['nullable', 'string', 'max:60'],
            'journeys' => ['required', 'array', 'min:1'], 'journeys.*' => [Rule::in(array_keys(Usability::JOURNEYS))],
        ], ['journeys.required' => 'Pick at least one task.']);
        /** @var User $user */
        $user = $request->user();
        $s = Usability::invite($d['name'], $d['email'], $d['family'], $d['industry'] ?? null, array_values($d['journeys']), $user);
        if (count(Usability::journeyKeys($s)) === 0) {
            DB::table('usability_sessions')->where('id', $s->id)->delete();

            return back()->withErrors(['journeys' => 'None of those tasks apply to this industry. Pick others.']);
        }

        return back()->with('status', 'usability-invited');
    }

    public function resend(Request $request): RedirectResponse
    {
        $s = Usability::find((string) $request->route('session'));
        abort_if($s->status === 'finished', 422);
        Notification::route('mail', $s->email)->notify(new UsabilityInvite((string) $s->name, Usability::startUrl($s), count(Usability::tasks($s)), Usability::script((string) $s->family, $s->industry_slug)['industry']));

        return back()->with('status', 'usability-invited');
    }

    public function show(Request $request): Response
    {
        $s = Usability::find((string) $request->route('session'));
        $titles = array_column(Usability::tasks($s), 'title', 'key');
        $results = (array) json_decode((string) ($s->results ?? '{}'), true);

        return Inertia::render('admin/usability/Session', [
            'session' => ['id' => $s->ulid, 'name' => $s->name, 'email' => $s->email, 'family' => Industry::FAMILIES[$s->family] ?? $s->family, 'status' => $s->status,
                'started' => $s->started_at !== null ? substr((string) $s->started_at, 0, 16) : null, 'finished' => $s->finished_at !== null ? substr((string) $s->finished_at, 0, 16) : null],
            'tasks' => array_map(fn ($key, $title) => ['key' => $key, 'title' => $title, 'outcome' => $results[$key]['outcome'] ?? null,
                'seconds' => $results[$key]['seconds'] ?? null, 'note' => $results[$key]['note'] ?? null], array_keys($titles), $titles),
            'events' => DB::table('usability_events')->where('session_id', $s->id)->orderBy('id')->limit(1000)->get()
                ->map(fn ($e) => ['id' => (int) $e->id, 'task' => $e->task !== null ? ($titles[$e->task] ?? $e->task) : null, 'kind' => $e->kind, 'path' => $e->path,
                    'detail' => $e->detail, 'seconds' => $e->seconds !== null ? (int) $e->seconds : null, 'at' => substr((string) $e->created_at, 11, 8)])->values(),
            'seo' => SeoData::private('Usability test')->toArray(),
        ]);
    }

    public function updateIssue(Request $request): RedirectResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(array_keys(Usability::STATUSES))], 'severity' => ['required', Rule::in(['high', 'medium', 'low'])], 'notes' => ['nullable', 'string', 'max:2000']]);
        $n = DB::table('usability_issues')->where('id', (int) $request->route('issue'))->update([...$d, 'updated_at' => now()]);
        abort_if($n === 0, 404);

        return back()->with('status', 'usability-issue-saved');
    }
}
