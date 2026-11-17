<?php

declare(strict_types=1);

namespace App\Domains\Support\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Partners\Partner;
use App\Domains\Seo\SeoData;
use App\Domains\Support\Help;
use App\Domains\Support\Ticket;
use App\Domains\Support\TicketMessage;
use App\Domains\Support\Tickets;
use App\Support\FormGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contacting support and following a ticket: members in their account (/app/support), everyone else through the
 * private link they were emailed (/support/t/…). Partners signed in to their partner account are recognised.
 */
final class TicketsController
{
    private const FILE_RULES = ['files' => ['nullable', 'array', 'max:3'], 'files.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf']];

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $partner = $this->partner($request);
        $article = $request->query('article') !== null ? Help::visible($user)->where('slug', (string) $request->query('article'))->first(['title', 'slug']) : null;
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('help/Contact', [
            'me' => $user !== null ? ['name' => $user->name, 'email' => $user->email] : ($partner !== null ? ['name' => $partner->contact_name, 'email' => $partner->email] : null),
            'categories' => collect(Ticket::CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'category' => $partner !== null ? 'partners' : (string) $request->query('topic', ''),
            'subject' => $article !== null ? 'Question about: '.$article->title : ($q !== '' ? mb_substr($q, 0, 160) : ''),
            // Before writing: the articles that might already answer it.
            'suggestions' => $q !== '' ? array_map(fn ($r) => ['title' => $r->title, 'href' => '/help/'.$r->slug], Help::search($q, $user, 3, false)) : [],
            'seo' => (new SeoData(title: 'Contact support', description: 'Ask the '.config('seo.site_name').' support team a question. A person replies, usually within a business day.', path: '/support/new'))->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $partner = $this->partner($request);
        $d = $request->validate([
            'name' => [$user === null ? 'required' : 'nullable', 'string', 'max:120'], 'email' => [$user === null ? 'required' : 'nullable', 'email:rfc', 'max:190'],
            'subject' => ['required', 'string', 'min:4', 'max:160'], 'body' => ['required', 'string', 'min:10', 'max:10000'], 'category' => ['nullable', Rule::in(array_keys(Ticket::CATEGORIES))],
            ...self::FILE_RULES, 'started' => [$user === null ? 'required' : 'nullable', 'string'],
        ]);
        if ($user === null) {
            FormGuard::check($request, 'body');
        }
        [$t, $token] = Tickets::open([
            'name' => $user->name ?? (string) $d['name'], 'email' => $user->email ?? (string) $d['email'], 'subject' => (string) $d['subject'], 'body' => (string) $d['body'], 'category' => $d['category'] ?? null,
        ], $user, $partner !== null && strtolower($partner->email) === strtolower($user->email ?? (string) $d['email']) ? $partner->id : null, 'web', FormGuard::ipHash($request), $this->files($request));
        if ($user !== null) {
            return redirect('/app/support/'.$t->ulid)->with('status', 'ticket-opened');
        }
        $request->session()->put('ticket_access.'.$t->ulid, $token);

        return redirect('/support/t/'.$t->ulid)->with('status', 'ticket-opened');
    }

    public function mine(Request $request): Response
    {
        $user = $this->user($request) ?? abort(401);

        return Inertia::render('help/Tickets', [
            'tickets' => Ticket::query()->where('user_id', $user->id)->orderByRaw("CASE WHEN status IN ('open','pending') THEN 0 ELSE 1 END")->orderByDesc('updated_at')->limit(100)->get()
                ->map(fn (Ticket $t) => ['id' => $t->ulid, 'number' => $t->number, 'subject' => $t->subject, 'status' => $t->status, 'statusLabel' => Ticket::STATUSES[$t->status], 'updated' => $t->updated_at->toDateString()])->values(),
            'seo' => SeoData::private('Support')->toArray(),
        ]);
    }

    public function show(Request $request, string $ticket): Response
    {
        $t = $this->find($request, $ticket);

        return Inertia::render('help/Ticket', [
            'ticket' => ['id' => $t->ulid, 'number' => $t->number, 'subject' => $t->subject, 'status' => $t->status, 'statusLabel' => Ticket::STATUSES[$t->status], 'category' => Ticket::CATEGORIES[$t->category],
                'created' => $t->created_at->toDateTimeString(), 'rating' => $t->rating],
            'messages' => $t->messages->where('internal', false)->map(fn (TicketMessage $m) => [
                'id' => $m->id, 'author' => $m->author, 'name' => $m->author === 'customer' ? $t->name : ($m->author === 'staff' ? (($m->user->first_name ?? 'Support').' (support)') : null),
                'body' => $m->body, 'at' => $m->created_at->toDateTimeString(),
                'files' => array_map(fn ($f, $i) => ['name' => $f['name'], 'href' => $this->base($t).'/files/'.$m->id.'/'.$i], $m->attachments ?? [], array_keys($m->attachments ?? [])),
            ])->values(),
            'base' => $this->base($t),
            'status' => session('status'),
            'seo' => SeoData::private($t->number)->toArray(),
        ]);
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $t = $this->find($request, $ticket);
        $d = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000'], ...self::FILE_RULES]);
        $now = Tickets::customerReply($t, (string) $d['body'], 'web', $this->files($request));
        if ($now->id !== $t->id) {
            // A closed ticket: the reply started a new one.
            if ($now->user_id === null) {
                $request->session()->put('ticket_access.'.$now->ulid, (string) $request->session()->get('ticket_access.'.$t->ulid));
                $now->forceFill(['access_hash' => $t->access_hash])->save();
            }

            return redirect($this->base($now))->with('status', 'ticket-opened');
        }

        return back()->with('status', 'ticket-replied');
    }

    public function solve(Request $request, string $ticket): RedirectResponse
    {
        Tickets::solveByCustomer($this->find($request, $ticket));

        return back()->with('status', 'ticket-solved');
    }

    public function rate(Request $request, string $ticket): RedirectResponse
    {
        $d = $request->validate(['rating' => ['required', 'in:good,bad'], 'comment' => ['nullable', 'string', 'max:1000']]);
        Tickets::rate($this->find($request, $ticket), (string) $d['rating'], $d['comment'] ?? null);

        return back()->with('status', 'ticket-rated');
    }

    /** An attachment, for the ticket's customer (staff download from the admin). */
    public function file(Request $request, string $ticket, int $message, int $index): StreamedResponse
    {
        $t = $this->find($request, $ticket);

        return self::download($t, (int) $request->route('message'), (int) $request->route('index'), false);
    }

    public static function download(Ticket $t, int $messageId, int $index, bool $staff): StreamedResponse
    {
        $m = TicketMessage::query()->where(['id' => $messageId, 'ticket_id' => $t->id])->when(! $staff, fn ($q) => $q->where('internal', false))->firstOrFail();
        $f = $m->attachments[$index] ?? abort(404);
        abort_unless(Storage::disk('private')->exists($f['path']), 404);

        return Storage::disk('private')->download($f['path'], $f['name'], ['Content-Type' => $f['type'], 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The ticket, if this person may see it: their own (members), or through their private link (kept in the session once opened). */
    private function find(Request $request, string $ulid): Ticket
    {
        $t = Ticket::query()->with(['messages.user', 'user'])->where('ulid', (string) ($request->route('ticket') ?? $ulid))->firstOrFail();
        $key = 'ticket_access.'.$t->ulid;
        if (is_string($k = $request->query('k')) && $k !== '') {
            $request->session()->put($key, $k);
        }
        $token = $request->session()->get($key);
        abort_unless(Tickets::canView($t, $this->user($request), is_string($token) ? $token : null), $this->user($request) === null ? 403 : 404);

        return $t;
    }

    private function base(Ticket $t): string
    {
        return $t->user_id !== null ? '/app/support/'.$t->ulid : '/support/t/'.$t->ulid;
    }

    /** @return list<UploadedFile> */
    private function files(Request $request): array
    {
        $files = $request->file('files');

        return is_array($files) ? array_values($files) : [];
    }

    private function user(Request $request): ?User
    {
        $u = $request->user();

        return $u instanceof User ? $u : null;
    }

    private function partner(Request $request): ?Partner
    {
        $email = $request->session()->get('partner_portal');

        return is_string($email) ? Partner::query()->where('email', $email)->first() : null;
    }
}
