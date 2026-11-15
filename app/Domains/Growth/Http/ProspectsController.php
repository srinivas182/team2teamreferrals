<?php

declare(strict_types=1);

namespace App\Domains\Growth\Http;

use App\Domains\Growth\Prospect;
use App\Domains\Growth\Prospects;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prospects: Admin → Growth (staff with growth.manage: everyone) and a network owner's page (their networks only).
 * The same screens serve both; `group` in the route is the owner's network.
 */
final class ProspectsController
{
    public const IMPORT_FIELDS = ['email' => 'Email', 'first_name' => 'First name', 'last_name' => 'Last name', 'business' => 'Business', 'phone' => 'Phone', 'city' => 'City', 'state' => 'State'];

    public function index(Request $request): Response
    {
        $group = $this->group($request);
        $user = $this->user($request);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'stage' => ['nullable', 'string', 'max:12'], 'source' => ['nullable', 'string', 'max:20'], 'industry' => ['nullable', 'integer'],
            'state' => ['nullable', 'string', 'size:2'], 'owner' => ['nullable', 'string', 'max:20'], 'temp' => ['nullable', 'in:hot,warm,cold'], 'tasks' => ['nullable', 'in:mine'], 'view' => ['nullable', 'in:list,board']]);
        $q = $this->filtered(Prospects::visibleTo($user, $group), $f, $user);
        $board = ($f['view'] ?? 'list') === 'board';
        $row = fn (Prospect $p) => ['id' => $p->ulid, 'name' => $p->name(), 'email' => $p->email, 'business' => $p->business, 'stage' => $p->stage, 'score' => $p->score, 'temp' => $p->temperature(),
            'source' => $p->source, 'city' => $p->city !== null ? $p->city->name.', '.$p->state_code : $p->state_code, 'industry' => $p->industry?->name, 'owner' => $p->owner?->name,
            'lastActivity' => $p->last_activity_at?->toIso8601String(), 'unsubscribed' => $p->unsubscribed_at !== null];
        $page = $board ? null : (clone $q)->with(['city', 'industry', 'owner'])->orderByDesc('score')->orderByDesc('last_activity_at')->paginate(50)->withQueryString();

        return Inertia::render('growth/Prospects', [
            ...$this->shared($request, $group),
            'filters' => (object) array_filter($f, fn ($v) => $v !== null),
            'prospects' => $page !== null ? ['data' => collect($page->items())->map($row)->values(), 'total' => $page->total(), 'links' => $page->linkCollection()->toArray()] : null,
            'board' => $board ? collect(Prospect::STAGES)->map(fn ($label, $stage) => ['stage' => $stage, 'label' => $label, 'count' => (clone $q)->where('stage', $stage)->count(),
                'items' => (clone $q)->with(['city', 'industry', 'owner'])->where('stage', $stage)->orderByDesc('score')->limit(30)->get()->map($row)->values()])->values() : null,
            'counts' => ['total' => (clone $q)->count(), 'hot' => (clone $q)->where('score', '>=', Prospects::HOT)->count(), 'myTasks' => DB::table('prospect_tasks')->where('assignee_id', $user->id)->whereNull('done_at')->count()],
            'views' => DB::table('prospect_views')->where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'filters'])->map(fn ($v) => ['id' => (int) $v->id, 'name' => (string) $v->name, 'filters' => json_decode((string) $v->filters, true)])->values(),
            'importPreview' => session('import_preview'),
            'importResult' => session('import_result'),
        ]);
    }

    public function show(Request $request, string $prospect): Response
    {
        $prospect = (string) $request->route('prospect');
        $group = $this->group($request);
        $user = $this->user($request);
        $p = $this->find($user, $group, $prospect);

        return Inertia::render('growth/Prospect', [
            ...$this->shared($request, $group),
            'prospect' => ['id' => $p->ulid, 'name' => $p->name(), 'email' => $p->email, 'first_name' => $p->first_name, 'last_name' => $p->last_name, 'business' => $p->business, 'phone' => $p->phone,
                'stage' => $p->stage, 'lostReason' => $p->lost_reason, 'score' => $p->score, 'temp' => $p->temperature(), 'source' => $p->source, 'sourceDetail' => $p->source_detail,
                'consentBasis' => $p->consent_basis, 'city' => $p->city?->name, 'state' => $p->state_code, 'industry' => $p->industry?->name, 'group' => $p->group?->name, 'ownerId' => $p->owner_id,
                'tags' => $p->tags ?? [], 'createdAt' => $p->created_at->toIso8601String(), 'joinedAt' => $p->joined_at?->toIso8601String(), 'unsubscribed' => $p->unsubscribed_at !== null,
                'member' => $p->user_id !== null],
            'activities' => DB::table('prospect_activities as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.prospect_id', $p->id)->orderByDesc('a.id')->limit(200)
                ->get(['a.id', 'a.type', 'a.body', 'a.created_at', 'u.first_name', 'u.last_name'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'type' => (string) $a->type, 'body' => $a->body, 'at' => now()->parse((string) $a->created_at)->toIso8601String(), 'by' => $a->first_name !== null ? trim($a->first_name.' '.$a->last_name) : null])->values(),
            'tasks' => DB::table('prospect_tasks as t')->join('users as u', 'u.id', '=', 't.assignee_id')->where('t.prospect_id', $p->id)->orderBy('t.done_at')->orderBy('t.due_at')
                ->get(['t.id', 't.title', 't.due_at', 't.done_at', 'u.first_name', 'u.last_name'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'title' => (string) $t->title, 'due' => now()->parse((string) $t->due_at)->toIso8601String(), 'done' => $t->done_at !== null, 'assignee' => trim($t->first_name.' '.$t->last_name)])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = $this->group($request);
        $user = $this->user($request);
        Prospects::visibleTo($user, $group);
        $d = $request->validate(['email' => ['required', 'email', 'max:190'], 'first_name' => ['nullable', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'], 'business' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'], 'industry_id' => ['nullable', 'integer', 'exists:industries,id'], 'city_id' => ['nullable', 'integer', 'exists:cities,id']]);
        if (Prospect::query()->where('email', mb_strtolower((string) $d['email']))->exists()) {
            throw ValidationException::withMessages(['email' => 'That person is already a prospect.']);
        }
        $p = Prospects::upsert([...$d, 'group_id' => $group?->id, 'industry_id' => $group !== null ? $group->industry_id : ($d['industry_id'] ?? null)], 'manual', 'added_by_staff', $user)
            ?? throw ValidationException::withMessages(['email' => 'That address asked never to be contacted.']);
        $p->forceFill(['owner_id' => $user->id])->save();

        return redirect($this->base($group).'/'.$p->ulid)->with('status', 'prospect-added');
    }

    public function update(Request $request, string $prospect): RedirectResponse
    {
        $prospect = (string) $request->route('prospect');
        $group = $this->group($request);
        $user = $this->user($request);
        $p = $this->find($user, $group, $prospect);
        $d = $request->validate(['first_name' => ['sometimes', 'nullable', 'string', 'max:80'], 'last_name' => ['sometimes', 'nullable', 'string', 'max:80'], 'business' => ['sometimes', 'nullable', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'], 'tags' => ['sometimes', 'array', 'max:20'], 'tags.*' => ['string', 'max:40']]);
        $p->fill($d)->save();
        Prospects::log($p, $user, 'note', 'Details updated.');

        return back()->with('status', 'prospect-saved');
    }

    public function stage(Request $request, string $prospect): RedirectResponse
    {
        $prospect = (string) $request->route('prospect');
        $group = $this->group($request);
        $user = $this->user($request);
        $d = $request->validate(['stage' => ['required', 'in:'.implode(',', array_keys(Prospect::STAGES))], 'lost_reason' => ['nullable', 'string', 'max:255']]);
        Prospects::setStage($this->find($user, $group, $prospect), (string) $d['stage'], $user, $d['lost_reason'] ?? null);

        return back()->with('status', 'prospect-saved');
    }

    public function assign(Request $request, string $prospect): RedirectResponse
    {
        $prospect = (string) $request->route('prospect');
        $group = $this->group($request);
        $user = $this->user($request);
        $d = $request->validate(['owner_id' => ['nullable', 'integer']]);
        $owner = isset($d['owner_id']) ? $this->assignable($group)->firstWhere('id', (int) $d['owner_id']) ?? throw ValidationException::withMessages(['owner_id' => 'Pick someone from the list.']) : null;
        Prospects::assign($this->find($user, $group, $prospect), $owner instanceof User ? $owner : null, $user);

        return back()->with('status', 'prospect-saved');
    }

    public function note(Request $request, string $prospect): RedirectResponse
    {
        $prospect = (string) $request->route('prospect');
        $group = $this->group($request);
        $user = $this->user($request);
        Prospects::note($this->find($user, $group, $prospect), $user, (string) $request->validate(['note' => ['required', 'string', 'max:5000']])['note']);

        return back()->with('status', 'prospect-noted');
    }

    public function task(Request $request, string $prospect): RedirectResponse
    {
        $prospect = (string) $request->route('prospect');
        $group = $this->group($request);
        $user = $this->user($request);
        $d = $request->validate(['title' => ['required', 'string', 'max:190'], 'due_at' => ['required', 'date'], 'assignee_id' => ['nullable', 'integer']]);
        $assignee = isset($d['assignee_id']) ? $this->assignable($group)->firstWhere('id', (int) $d['assignee_id']) : $user;
        if (! $assignee instanceof User) {
            throw ValidationException::withMessages(['assignee_id' => 'Pick someone from the list.']);
        }
        Prospects::addTask($this->find($user, $group, $prospect), $user, $assignee, (string) $d['title'], (string) $d['due_at']);

        return back()->with('status', 'prospect-task-added');
    }

    public function toggleTask(Request $request, int $task): RedirectResponse
    {
        $task = (int) $request->route('task');
        $group = $this->group($request);
        $user = $this->user($request);
        $prospectId = (int) (DB::table('prospect_tasks')->where('id', $task)->value('prospect_id') ?? abort(404));
        Prospects::visibleTo($user, $group)->whereKey($prospectId)->firstOrFail();
        Prospects::completeTask($task, $user);

        return back();
    }

    public function forget(Request $request, string $prospect): RedirectResponse
    {
        $prospect = (string) $request->route('prospect');
        $group = $this->group($request);
        $user = $this->user($request);
        Prospects::forget($this->find($user, $group, $prospect), $user);

        return redirect($this->base($group))->with('status', 'prospect-forgotten');
    }

    /** Change several at once: stage, owner, or forget. */
    public function bulk(Request $request): RedirectResponse
    {
        $group = $this->group($request);
        $user = $this->user($request);
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['string', 'max:30'], 'action' => ['required', 'in:stage,owner,forget'],
            'stage' => ['required_if:action,stage', 'nullable', 'in:'.implode(',', array_keys(Prospect::STAGES))], 'lost_reason' => ['nullable', 'string', 'max:255'], 'owner_id' => ['nullable', 'integer']]);
        $owner = $d['action'] === 'owner' && isset($d['owner_id']) ? $this->assignable($group)->firstWhere('id', (int) $d['owner_id']) : null;
        foreach (Prospects::visibleTo($user, $group)->whereIn('ulid', (array) $d['ids'])->get() as $p) {
            match ($d['action']) {
                'stage' => Prospects::setStage($p, (string) $d['stage'], $user, $d['lost_reason'] ?? null),
                'owner' => Prospects::assign($p, $owner instanceof User ? $owner : null, $user),
                default => Prospects::forget($p, $user),
            };
        }

        return back()->with('status', 'prospects-updated');
    }

    /** Import, step 1: read the file and show its columns for matching. */
    public function importPreview(Request $request): RedirectResponse
    {
        $group = $this->group($request);
        Prospects::visibleTo($this->user($request), $group);
        $file = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']])['file'];
        $token = (string) Str::uuid();
        Storage::disk('local')->putFileAs('prospect-imports', $file, $token.'.csv');
        $rows = $this->readCsv($token, 6);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'That file is empty.']);
        }
        $headers = array_map(fn ($h) => mb_substr(trim((string) $h), 0, 60), $rows[0]);
        $guess = [];
        foreach ($headers as $i => $h) {
            $n = str_replace([' ', '-', '_'], '', mb_strtolower($h));
            $guess[$i] = match (true) {
                str_contains($n, 'email') => 'email', in_array($n, ['firstname', 'first', 'givenname'], true) => 'first_name', in_array($n, ['lastname', 'last', 'surname', 'familyname'], true) => 'last_name',
                in_array($n, ['company', 'business', 'brokerage', 'organization', 'organisation'], true) => 'business', str_contains($n, 'phone') || str_contains($n, 'mobile') => 'phone',
                $n === 'city' => 'city', $n === 'state' || $n === 'st' => 'state', default => '',
            };
        }

        return back()->with('import_preview', ['token' => $token, 'name' => mb_substr($file->getClientOriginalName(), 0, 120), 'headers' => $headers, 'guess' => $guess, 'sample' => array_slice($rows, 1, 5)]);
    }

    /** Import, step 2: with the columns matched and the "not a bought list" confirmation. */
    public function importConfirm(Request $request): RedirectResponse
    {
        $group = $this->group($request);
        $user = $this->user($request);
        Prospects::visibleTo($user, $group);
        $d = $request->validate(['token' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'in:,'.implode(',', array_keys(self::IMPORT_FIELDS))],
            'not_purchased' => ['accepted']], ['not_purchased.accepted' => 'Confirm these people weren’t bought from a list: bought lists can’t be imported.']);
        $map = array_filter((array) $d['mapping'], fn ($v) => $v !== null && $v !== '');
        if (! in_array('email', $map, true)) {
            throw ValidationException::withMessages(['mapping' => 'Match one column to Email.']);
        }
        $rows = $this->readCsv((string) $d['token'], Prospects::IMPORT_MAX_ROWS + 2);
        abort_if($rows === [], 422, 'That import has expired. Please upload the file again.');
        $data = [];
        foreach (array_slice($rows, 1) as $r) {
            $item = [];
            foreach ($map as $col => $field) {
                $item[$field] = trim((string) ($r[(int) $col] ?? ''));
            }
            $data[] = $item;
        }
        $result = Prospects::import($data, $user, (string) $d['name'], $group);
        Storage::disk('local')->delete('prospect-imports/'.$d['token'].'.csv');

        return back()->with('import_result', $result)->with('status', 'prospects-imported');
    }

    public function saveView(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'filters' => ['required', 'array']]);
        DB::table('prospect_views')->insert(['user_id' => $this->user($request)->id, 'name' => $d['name'], 'filters' => json_encode(array_intersect_key((array) $d['filters'], array_flip(['q', 'stage', 'source', 'industry', 'state', 'owner', 'temp', 'tasks', 'view']))),
            'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'prospect-view-saved');
    }

    public function deleteView(Request $request, int $view): RedirectResponse
    {
        $view = (int) $request->route('view');
        DB::table('prospect_views')->where(['id' => $view, 'user_id' => $this->user($request)->id])->delete();

        return back();
    }

    /** From a notification: to the prospect in whichever screen this person uses. */
    public function go(Request $request, string $prospect): RedirectResponse
    {
        $prospect = (string) $request->route('prospect');
        $user = $this->user($request);
        if ($prospect === 'tasks') {
            $owned = Group::query()->whereIn('id', DB::table('group_owners')->where('user_id', $user->id)->pluck('group_id'))->first();

            return redirect(Prospects::isStaff($user) ? '/admin/growth/prospects?tasks=mine' : ($owned !== null ? '/app/group/'.$owned->slug.'/prospects?tasks=mine' : '/app'));
        }
        $p = Prospect::query()->where('ulid', $prospect)->firstOrFail();
        if (Prospects::isStaff($user)) {
            return redirect('/admin/growth/prospects/'.$p->ulid);
        }
        $g = Group::query()->whereIn('id', DB::table('group_owners')->where('user_id', $user->id)->pluck('group_id'))
            ->where(fn ($q) => $q->where('id', $p->group_id)->orWhere('industry_id', $p->industry_id))->first() ?? abort(404);

        return redirect('/app/group/'.$g->slug.'/prospects/'.$p->ulid);
    }

    /**
     * @param  Builder<Prospect>  $q
     * @param  array<string, mixed>  $f
     * @return Builder<Prospect>
     */
    private function filtered(Builder $q, array $f, User $user): Builder
    {
        return $q
            ->when($f['q'] ?? null, fn ($w, $s) => $w->where(fn ($x) => $x->where('email', 'like', '%'.mb_strtolower($s).'%')->orWhere('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")->orWhere('business', 'like', "%{$s}%")))
            ->when($f['stage'] ?? null, fn ($w, $s) => $w->where('stage', $s))
            ->when($f['source'] ?? null, fn ($w, $s) => $w->where('source', $s))
            ->when($f['industry'] ?? null, fn ($w, $s) => $w->where('industry_id', (int) $s))
            ->when($f['state'] ?? null, fn ($w, $s) => $w->where('state_code', strtoupper($s)))
            ->when($f['owner'] ?? null, fn ($w, $s) => $s === 'mine' ? $w->where('owner_id', $user->id) : ($s === 'none' ? $w->whereNull('owner_id') : $w->where('owner_id', (int) $s)))
            ->when($f['temp'] ?? null, fn ($w, $t) => match ($t) {
                'hot' => $w->where('score', '>=', Prospects::HOT), 'warm' => $w->whereBetween('score', [Prospects::WARM, Prospects::HOT - 1]), default => $w->where('score', '<', Prospects::WARM),
            })
            ->when(($f['tasks'] ?? null) === 'mine', fn ($w) => $w->whereIn('id', DB::table('prospect_tasks')->where('assignee_id', $user->id)->whereNull('done_at')->select('prospect_id')));
    }

    /** @return array<string, mixed> */
    private function shared(Request $request, ?Group $group): array
    {
        $user = $this->user($request);

        return [
            'layout' => $group !== null ? 'portal' : 'admin',
            'base' => $this->base($group),
            'actions' => collect([
                'bulk' => ['admin.growth.prospects.bulk', 'group.prospects.bulk'], 'import' => ['admin.growth.prospects.import', 'group.prospects.import'],
                'importConfirm' => ['admin.growth.prospects.import.confirm', 'group.prospects.import.confirm'], 'views' => ['admin.growth.prospects.views', 'group.prospects.views'],
            ])->map(fn (array $names) => $group !== null ? route($names[1], ['group' => $group->slug], false) : route($names[0], [], false))->all(),
            'group' => $group !== null ? ['slug' => $group->slug, 'name' => $group->name] : null,
            'stages' => Prospect::STAGES,
            'sources' => Prospect::SOURCES,
            'importFields' => self::IMPORT_FIELDS,
            'people' => $this->assignable($group)->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'industries' => $group === null ? Industry::query()->orderBy('name')->get(['id', 'name'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values() : [],
            'me' => $user->id,
            'status' => session('status'),
            'seo' => SeoData::private('Prospects')->toArray(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, User> who prospects can be given to */
    private function assignable(?Group $group): \Illuminate\Support\Collection
    {
        $staff = User::permission('growth.manage')->where('is_active', true)->get();
        $owners = $group !== null ? $group->owners()->where('is_active', true)->get() : collect();

        return $staff->merge($owners)->unique('id')->sortBy('name')->values();
    }

    private function base(?Group $group): string
    {
        return $group !== null ? '/app/group/'.$group->slug.'/prospects' : '/admin/growth/prospects';
    }

    /** The owner's network, on /app/group/{group}/prospects; null in Admin → Growth. */
    private function group(Request $request): ?Group
    {
        $g = $request->route('group');

        return $g instanceof Group ? $g : (is_string($g) ? Group::query()->where('slug', $g)->firstOrFail() : null);
    }

    private function find(User $user, ?Group $group, string $ulid): Prospect
    {
        return Prospects::visibleTo($user, $group)->with(['city', 'industry', 'group', 'owner'])->where('ulid', $ulid)->firstOrFail();
    }

    /** @return list<list<string>> */
    private function readCsv(string $token, int $max): array
    {
        $path = 'prospect-imports/'.$token.'.csv';
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }
        $h = fopen(Storage::disk('local')->path($path), 'r');
        if ($h === false) {
            return [];
        }
        $rows = [];
        while (count($rows) < $max && ($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($r !== [null]) {
                $rows[] = array_map(fn ($c) => (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c), $r);
            }
        }
        fclose($h);

        return $rows;
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
