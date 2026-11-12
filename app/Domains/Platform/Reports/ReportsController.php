<?php

declare(strict_types=1);

namespace App\Domains\Platform\Reports;

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Referrals\Dashboard\Period;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reports: Admin → Reports (whole network) and Owner → Reports (/app/group/{group}/reports, that network only).
 * Build a report, preview it, save it, export it, and schedule it by email. Route values are read by name
 * (owner addresses start with the network, so positional method arguments would receive the wrong value).
 */
final class ReportsController
{
    public function index(Request $request): Response
    {
        [$user, $group, $base] = $this->context($request);
        $reports = DB::table('saved_reports')->where('group_id', $group?->id)->when($group === null, fn ($q) => $q->whereNull('group_id'))->orderBy('name')->get();
        $schedules = DB::table('report_schedules')->whereIn('report_id', $reports->pluck('id'))->get();
        $users = User::query()->whereIn('id', $schedules->flatMap(fn ($s) => (array) json_decode((string) $s->recipients, true)))->get()->pluck('name', 'id'); // name is built from first and last name

        return Inertia::render('reports/Reports', [
            'base' => $base, 'scope' => $group?->name,
            'previewUrl' => $group !== null ? route('group.reports.preview', $group->slug, false) : route('admin.reports.preview', [], false),
            'datasets' => collect(Datasets::SETS)->filter(fn ($d, $k) => Datasets::allowed($user, $k, $group))->map(fn ($d, $k) => ['key' => $k, 'label' => $d['label'], 'columns' => $d['columns'], 'groupBy' => $d['groupBy']])->values(),
            'presets' => Period::PRESETS,
            'reports' => $reports->map(fn ($r) => ['id' => (string) $r->ulid, 'name' => (string) $r->name, 'dataset' => (string) $r->dataset, 'preset' => (string) $r->preset, 'groupBy' => $r->group_by,
                'schedules' => $schedules->where('report_id', $r->id)->map(fn ($s) => ['id' => (int) $s->id, 'frequency' => (string) $s->frequency, 'weekday' => $s->weekday, 'day' => $s->day, 'hour' => (int) $s->hour,
                    'timezone' => (string) $s->timezone, 'paused' => (bool) $s->paused, 'next' => (string) $s->next_run_at,
                    'recipients' => array_values(array_map(fn ($id) => (string) ($users[$id] ?? 'Removed user'), (array) json_decode((string) $s->recipients, true)))])->values()])->values(),
            'deliveries' => DB::table('report_deliveries as d')->join('report_schedules as s', 's.id', '=', 'd.schedule_id')->join('saved_reports as r', 'r.id', '=', 's.report_id')
                ->leftJoin('users as u', 'u.id', '=', 'd.user_id')->whereIn('r.id', $reports->pluck('id'))->orderByDesc('d.id')->limit(30)
                ->get(['r.name as report', 'u.first_name', 'u.last_name', 'd.status', 'd.attempts', 'd.rows', 'd.error', 'd.updated_at'])
                ->map(fn ($d) => ['report' => (string) $d->report, 'to' => trim($d->first_name.' '.$d->last_name), 'status' => (string) $d->status, 'attempts' => (int) $d->attempts, 'rows' => (int) $d->rows, 'error' => $d->error, 'updated_at' => (string) $d->updated_at])->values(),
            // Admins can send to staff allowed to see the report; owners send to themselves.
            'recipients' => $group !== null ? [['id' => $user->id, 'name' => $user->name]]
                : User::role([Permissions::SUPER_ADMIN, Permissions::STAFF])->orderBy('first_name')->orderBy('last_name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'timezone' => $user->timezone ?? 'America/Chicago',
            'preview' => session('report_preview'), 'status' => session('status'),
            'seo' => SeoData::private('Reports')->toArray(),
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        [$user, $group] = $this->context($request);
        $d = $this->definition($request, $user, $group);
        $result = Datasets::run($d['dataset'], $group, Period::from($d['preset']), $d['columns'], $d['filters'], $d['group_by'], Datasets::SCREEN_ROWS);

        return back()->with('report_preview', [...$result, 'definition' => $d])->withInput();
    }

    public function save(Request $request): RedirectResponse
    {
        [$user, $group] = $this->context($request);
        $d = $this->definition($request, $user, $group);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:80']])['name'];
        DB::table('saved_reports')->insert(['ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'group_id' => $group?->id, 'name' => $name, 'dataset' => $d['dataset'],
            'columns' => json_encode($d['columns']), 'filters' => json_encode($d['filters']), 'group_by' => $d['group_by'], 'preset' => $d['preset'], 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'report-saved');
    }

    /** CSV now (up to the attachment limit); larger reports are built in the background and emailed as a link. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$user, $group] = $this->context($request);
        $r = $this->report((string) $request->route('report'), $group);
        $result = Datasets::runSaved($r, $group, Period::from((string) $r->preset));
        if ($result['total'] > Schedules::attachRows()) {
            BuildReport::dispatch((int) $r->id, $user->id);

            return back()->with('status', 'report-building');
        }

        return response()->streamDownload(fn () => print (Datasets::csv($result)), Str::slug((string) $r->name).'.csv', ['Content-Type' => 'text/csv']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        [, $group] = $this->context($request);
        DB::table('saved_reports')->where('id', $this->report((string) $request->route('report'), $group)->id)->delete();

        return back()->with('status', 'report-deleted');
    }

    public function schedule(Request $request): RedirectResponse
    {
        [$user, $group] = $this->context($request);
        $r = $this->report((string) $request->route('report'), $group);
        $d = $request->validate(['frequency' => ['required', 'in:weekly,monthly'], 'weekday' => ['required_if:frequency,weekly', 'nullable', 'integer', 'between:1,7'],
            'day' => ['required_if:frequency,monthly', 'nullable', 'integer', 'between:1,28'], 'hour' => ['required', 'integer', 'between:0,23'], 'timezone' => ['required', 'timezone:all'],
            'recipients' => ['required', 'array', 'min:1'], 'recipients.*' => ['integer']]);
        $allowed = $group !== null ? [$user->id] : User::role([Permissions::SUPER_ADMIN, Permissions::STAFF])->pluck('id')->all();
        $recipients = array_values(array_intersect(array_map('intval', $d['recipients']), $allowed));
        if ($recipients === []) {
            throw ValidationException::withMessages(['recipients' => 'Choose who receives this report.']);
        }
        DB::table('report_schedules')->insert(['report_id' => $r->id, 'frequency' => $d['frequency'], 'weekday' => $d['frequency'] === 'weekly' ? (int) $d['weekday'] : null,
            'day' => $d['frequency'] === 'monthly' ? (int) $d['day'] : null, 'hour' => (int) $d['hour'], 'timezone' => $d['timezone'], 'recipients' => json_encode($recipients), 'paused' => false,
            'next_run_at' => Schedules::nextRun($d['frequency'], isset($d['weekday']) ? (int) $d['weekday'] : null, isset($d['day']) ? (int) $d['day'] : null, (int) $d['hour'], $d['timezone']),
            'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'report-scheduled');
    }

    public function scheduleAction(Request $request): RedirectResponse
    {
        [, $group] = $this->context($request);
        $action = (string) $request->route('action');
        $s = DB::table('report_schedules')->where('id', (int) $request->route('schedule'))->first();
        abort_if($s === null, 404);
        $this->report((string) DB::table('saved_reports')->where('id', $s->report_id)->value('ulid'), $group);
        match ($action) {
            'pause' => DB::table('report_schedules')->where('id', $s->id)->update(['paused' => true, 'updated_at' => now()]),
            'resume' => DB::table('report_schedules')->where('id', $s->id)->update(['paused' => false, 'updated_at' => now(),
                'next_run_at' => Schedules::nextRun((string) $s->frequency, $s->weekday, $s->day, (int) $s->hour, (string) $s->timezone)]),
            default => DB::table('report_schedules')->where('id', $s->id)->delete(),
        };

        return back()->with('status', 'schedule-'.$action);
    }

    /** A large report's private download: a signed link, for the person it was sent to. */
    public function file(Request $request): BinaryFileResponse
    {
        $file = (string) $request->route('file');
        abort_unless($request->hasValidSignature() && (int) $request->query('for') === (int) $request->user()?->id, 403);
        abort_unless((bool) preg_match('/^[0-9A-Za-z-]+\.csv$/', $file) && Storage::disk('local')->exists('reports/'.$file), 404);

        return response()->download(Storage::disk('local')->path('reports/'.$file), 'report.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{dataset: string, columns: list<string>, filters: array<string, string>, group_by: ?string, preset: string} */
    private function definition(Request $request, User $user, ?Group $group): array
    {
        $d = $request->validate(['dataset' => ['required', Rule::in(array_keys(Datasets::SETS))], 'columns' => ['array'], 'columns.*' => ['string'], 'filters' => ['array'],
            'filters.*' => ['nullable', 'string', 'max:60'], 'group_by' => ['nullable', 'string'], 'preset' => ['required', Rule::in(array_diff(array_keys(Period::PRESETS), ['custom']))]]);
        abort_unless(Datasets::allowed($user, (string) $d['dataset'], $group), 403);
        $def = Datasets::SETS[(string) $d['dataset']];

        return ['dataset' => (string) $d['dataset'], 'columns' => array_values(array_intersect(array_map('strval', (array) ($d['columns'] ?? [])), array_keys($def['columns']))),
            'filters' => array_map('strval', array_filter((array) ($d['filters'] ?? []), fn ($v) => is_string($v) && $v !== '')),
            'group_by' => isset($d['group_by']) && isset($def['groupBy'][(string) $d['group_by']]) ? (string) $d['group_by'] : null, 'preset' => (string) $d['preset']];
    }

    private function report(string $ulid, ?Group $group): stdClass
    {
        $r = DB::table('saved_reports')->where('ulid', $ulid)->first();
        abort_if($r === null || (int) $r->group_id !== (int) $group?->id, 404);

        return $r;
    }

    /** @return array{0: User, 1: ?Group, 2: string} */
    private function context(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $slug = $request->route('group');
        if ($slug !== null) {
            $group = $slug instanceof Group ? $slug : Group::query()->where('slug', (string) $slug)->firstOrFail();
            abort_unless($group->isOwnedBy($user) || $user->isStaff(), 403);

            return [$user, $group, '/app/group/'.$group->slug.'/reports'];
        }
        abort_unless($user->isStaff() && $user->can('referrals.view'), 403);

        return [$user, null, '/admin/reports'];
    }
}
