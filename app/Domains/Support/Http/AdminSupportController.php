<?php

declare(strict_types=1);

namespace App\Domains\Support\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Directory\Directory;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use App\Domains\Support\Ticket;
use App\Domains\Support\TicketMessage;
use App\Domains\Support\Tickets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin → Support: the ticket inbox, each ticket with the customer's context, saved replies and response-time targets. */
final class AdminSupportController
{
    public const VIEWS = ['mine' => 'Mine', 'unassigned' => 'Unassigned', 'open' => 'All open', 'pending' => 'Waiting on customer', 'solved' => 'Solved', 'all' => 'Everything'];

    public function index(Request $request): Response
    {
        $me = $this->user($request);
        $d = $request->validate(['view' => ['nullable', Rule::in(array_keys(self::VIEWS))], 'q' => ['nullable', 'string', 'max:120'], 'priority' => ['nullable', Rule::in(array_keys(Ticket::PRIORITIES))]]);
        $view = (string) ($d['view'] ?? 'open');
        $scope = fn (string $v): Builder => Ticket::query()->when($v === 'mine', fn ($q) => $q->where('assignee_id', $me->id)->whereIn('status', ['open', 'pending']))
            ->when($v === 'unassigned', fn ($q) => $q->whereNull('assignee_id')->where('status', 'open'))
            ->when($v === 'open', fn ($q) => $q->where('status', 'open'))
            ->when($v === 'pending', fn ($q) => $q->where('status', 'pending'))
            ->when($v === 'solved', fn ($q) => $q->whereIn('status', ['solved', 'closed']));
        $rows = $scope($view)->with('assignee')
            ->when(filled($d['q'] ?? null), function ($q) use ($d) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $d['q']).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('subject', 'like', $like)->orWhere('email', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->when(filled($d['priority'] ?? null), fn ($q) => $q->where('priority', $d['priority']))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")->orderBy('first_response_due_at')->limit(200)->get();

        return Inertia::render('admin/support/Index', [
            'view' => $view, 'q' => $d['q'] ?? '', 'priority' => $d['priority'] ?? '',
            'views' => collect(self::VIEWS)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'count' => $k === 'all' || $k === 'solved' ? null : $scope($k)->count()])->values(),
            'tickets' => $rows->map(fn (Ticket $t) => [
                'id' => $t->ulid, 'number' => $t->number, 'subject' => $t->subject, 'name' => $t->name, 'email' => $t->email, 'status' => $t->status, 'priority' => $t->priority,
                'category' => Ticket::CATEGORIES[$t->category], 'assignee' => $t->assignee?->name, 'updated' => $t->updated_at->toDateTimeString(), 'due' => $this->due($t), 'source' => $t->source,
            ])->values(),
            'stats' => $this->stats(),
            'seo' => SeoData::private('Support')->toArray(),
        ]);
    }

    public function show(Request $request, string $ticket): Response
    {
        $t = $this->find($ticket);

        return Inertia::render('admin/support/Ticket', [
            'ticket' => [
                'id' => $t->ulid, 'number' => $t->number, 'subject' => $t->subject, 'name' => $t->name, 'email' => $t->email, 'status' => $t->status, 'priority' => $t->priority, 'category' => $t->category,
                'assigneeId' => $t->assignee_id, 'source' => $t->source, 'created' => $t->created_at->toDateTimeString(), 'due' => $this->due($t), 'rating' => $t->rating, 'ratingComment' => $t->rating_comment,
                'firstResponse' => $t->first_responded_at?->toDateTimeString(),
            ],
            'messages' => $t->messages->map(fn (TicketMessage $m) => [
                'id' => $m->id, 'author' => $m->author, 'name' => $m->author === 'customer' ? $t->name : ($m->user->name ?? 'System'), 'body' => $m->body, 'internal' => $m->internal, 'via' => $m->via,
                'at' => $m->created_at->toDateTimeString(),
                'files' => array_map(fn ($f, $i) => ['name' => $f['name'], 'href' => '/admin/support/'.$t->ulid.'/files/'.$m->id.'/'.$i], $m->attachments ?? [], array_keys($m->attachments ?? [])),
            ])->values(),
            'context' => $this->context($t),
            'staff' => Tickets::staff()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->sortBy('name')->values(),
            'macros' => DB::table('support_macros')->orderBy('title')->get(['id', 'title', 'body'])->map(fn ($m) => (array) $m)->values(),
            'categories' => Ticket::CATEGORIES, 'priorities' => Ticket::PRIORITIES, 'statuses' => Ticket::STATUSES,
            'flash' => session('status'),
            'seo' => SeoData::private($t->number)->toArray(),
        ]);
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $t = $this->find($ticket);
        $d = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000'], 'internal' => ['boolean'], 'status' => ['nullable', Rule::in(['open', 'pending', 'solved'])],
            'files' => ['nullable', 'array', 'max:3'], 'files.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf']]);
        $files = is_array($request->file('files')) ? array_values($request->file('files')) : [];
        Tickets::staffReply($t, $this->user($request), (string) $d['body'], (bool) ($d['internal'] ?? false), $d['status'] ?? null, $files);

        return back()->with('status', ($d['internal'] ?? false) ? 'ticket-note-added' : 'ticket-reply-sent');
    }

    public function update(Request $request, string $ticket): RedirectResponse
    {
        $t = $this->find($ticket);
        $staff = Tickets::staff()->pluck('id')->all();
        $d = $request->validate(['status' => ['sometimes', Rule::in(array_keys(Ticket::STATUSES))], 'priority' => ['sometimes', Rule::in(array_keys(Ticket::PRIORITIES))],
            'category' => ['sometimes', Rule::in(array_keys(Ticket::CATEGORIES))], 'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::in($staff)]]);
        /** @var array{status?: string, priority?: string, category?: string, assignee_id?: ?int} $d */
        Tickets::update($t, $this->user($request), $d);

        return back()->with('status', 'ticket-updated');
    }

    public function file(Request $request, string $ticket, int $message, int $index): StreamedResponse
    {
        return TicketsController::download($this->find($ticket), (int) $request->route('message'), (int) $request->route('index'), true);
    }

    public function macros(): Response
    {
        $s = app(Settings::class);

        return Inertia::render('admin/support/Settings', [
            'macros' => DB::table('support_macros')->orderBy('title')->get(['id', 'title', 'body'])->map(fn ($m) => (array) $m)->values(),
            'sla' => collect(Ticket::PRIORITIES)->map(fn ($label, $p) => ['priority' => $p, 'label' => $label, 'first' => Tickets::sla($p)[0], 'resolve' => Tickets::sla($p)[1]])->values(),
            'supportAddress' => filled(config('mail.inbound.domain')) ? 'support@'.config('mail.inbound.domain') : null,
            'flash' => session('status'),
            'seo' => SeoData::private('Support settings')->toArray(),
        ]);
    }

    public function saveMacro(Request $request, ?int $macro = null): RedirectResponse
    {
        $id = $request->route('macro') !== null ? (int) $request->route('macro') : null;
        $d = $request->validate(['title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:5000']]);
        if ($id !== null) {
            abort_unless(DB::table('support_macros')->where('id', $id)->update([...$d, 'updated_at' => now()]) === 1, 404);
        } else {
            DB::table('support_macros')->insert([...$d, 'created_by' => $request->user()?->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('status', 'macro-saved');
    }

    public function deleteMacro(Request $request, int $macro): RedirectResponse
    {
        DB::table('support_macros')->where('id', (int) $request->route('macro'))->delete();

        return back()->with('status', 'macro-deleted');
    }

    public function saveSla(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (array_keys(Ticket::PRIORITIES) as $p) {
            $rules["sla.{$p}.first"] = ['required', 'integer', 'between:1,720'];
            $rules["sla.{$p}.resolve"] = ['required', 'integer', 'between:1,2160', "gte:sla.{$p}.first"];
        }
        $d = $request->validate($rules);
        $s = app(Settings::class);
        foreach ((array) $d['sla'] as $p => $v) {
            $s->set("support.sla.{$p}.first", (string) $v['first']);
            $s->set("support.sla.{$p}.resolve", (string) $v['resolve']);
        }
        activity('support')->causedBy($request->user())->event('support-sla-saved')->log('Changed support response-time targets');

        return back()->with('status', 'settings-saved');
    }

    /**
     * What staff need to know about the customer: their account, seats and billing, and earlier tickets.
     *
     * @return array<string, mixed>
     */
    private function context(Ticket $t): array
    {
        $user = $t->user ?? User::query()->where('email', $t->email)->first();
        $out = ['member' => null, 'partner' => null, 'earlier' => Ticket::query()->where('email', $t->email)->where('id', '!=', $t->id)->orderByDesc('id')->limit(5)->get()
            ->map(fn (Ticket $x) => ['id' => $x->ulid, 'number' => $x->number, 'subject' => $x->subject, 'status' => Ticket::STATUSES[$x->status]])->values()];
        if ($user !== null) {
            $team = \App\Domains\Network\Seats\Models\Team::of($user);
            $out['member'] = [
                'name' => $user->name, 'joined' => $user->created_at?->toDateString(), 'team' => $team?->name, 'teamSlug' => $team?->slug,
                'seats' => $team !== null ? DB::table('seats as s')->join('groups as g', 'g.id', '=', 's.group_id')->where('s.primary_team_id', $team->id)->get(['s.territory_name', 's.status', 'g.name'])
                    ->map(fn ($s) => $s->territory_name.' · '.$s->name.' · '.$s->status)->values() : [],
                'unpaid' => $team !== null ? DB::table('invoices')->where(['owner_type' => 'team', 'owner_id' => $team->id])->whereIn('status', ['failed', 'action_required'])->count() : 0,
                'filled' => $team !== null && DB::table('seats')->where('primary_team_id', $team->id)->whereIn('status', Directory::FILLED)->exists(),
            ];
        }
        $partner = $t->partner_id !== null ? DB::table('partners')->where('id', $t->partner_id)->first(['business_name', 'status', 'billing_status', 'slug']) : null;
        if ($partner !== null) {
            $out['partner'] = ['name' => $partner->business_name, 'status' => $partner->status, 'billing' => $partner->billing_status, 'slug' => $partner->slug];
        }

        return $out;
    }

    /** @return array{label: string, overdue: bool}|null */
    private function due(Ticket $t): ?array
    {
        if ($t->status !== 'open') {
            return null;
        }
        $at = $t->first_responded_at === null ? $t->first_response_due_at : $t->resolution_due_at;
        if ($at === null) {
            return null;
        }
        $kind = $t->first_responded_at === null ? 'Reply' : 'Resolve';

        return ['label' => $at->isPast() ? $kind.' overdue by '.$at->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE) : $kind.' within '.now()->diffForHumans($at, \Carbon\CarbonInterface::DIFF_ABSOLUTE), 'overdue' => $at->isPast()];
    }

    /** @return array{open: int, overdue: int, firstResponseHours: ?float, satisfaction: ?int} last 30 days */
    private function stats(): array
    {
        $since = now()->subDays(30);
        $responded = Ticket::query()->where('created_at', '>=', $since)->whereNotNull('first_responded_at')->get(['created_at', 'first_responded_at']);
        $rated = Ticket::query()->where('created_at', '>=', $since)->whereNotNull('rating')->pluck('rating');

        return [
            'open' => Ticket::query()->where('status', 'open')->count(),
            'overdue' => Ticket::query()->where('status', 'open')->whereNull('first_responded_at')->where('first_response_due_at', '<', now())->count(),
            'firstResponseHours' => $responded->isNotEmpty() ? round($responded->sum(fn (Ticket $t) => $t->created_at->diffInMinutes($t->first_responded_at) / 60) / $responded->count(), 1) : null,
            'satisfaction' => $rated->isNotEmpty() ? (int) round($rated->filter(fn ($r) => $r === 'good')->count() * 100 / $rated->count()) : null,
        ];
    }

    private function find(string $ulid): Ticket
    {
        return Ticket::query()->with(['messages.user', 'user', 'assignee'])->where('ulid', $ulid)->firstOrFail();
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
