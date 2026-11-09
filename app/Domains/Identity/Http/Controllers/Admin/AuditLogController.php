<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Models\User;
use App\Domains\Seo\SeoData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Who did what, when, from where — with before/after values. Filterable and exportable. */
final class AuditLogController
{
    public function index(Request $request): Response
    {
        $page = $this->query($request)->paginate(50)->withQueryString();

        return Inertia::render('admin/AuditLog', [
            'entries' => collect($page->items())->map(fn (Activity $a) => $this->present($a)),
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'filters' => $request->only(['q', 'log', 'from', 'to']),
            'logs' => ['auth' => 'Sign-in', 'security' => 'Security', 'accounts' => 'Account changes', 'admin' => 'Admin actions'],
            'seo' => SeoData::private('Audit log')->toArray(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request);
        activity('admin')->causedBy($request->user())->event('audit-exported')->withProperties(['filters' => $request->only(['q', 'log', 'from', 'to'])])->log('Exported the audit log');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['When (UTC)', 'Log', 'Event', 'Description', 'By', 'Subject', 'IP', 'Device', 'Changes']);
            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $a) {
                    /** @var Activity $a */
                    $p = $this->present($a);
                    fputcsv($out, [$a->created_at?->toDateTimeString(), $a->log_name, $a->event, $a->description, $p['causer'], $p['subject'], $p['ip'], $p['device'], json_encode($p['changes'])]);
                }
            });
            fclose($out);
        }, 'audit-log-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return Builder<Activity> */
    private function query(Request $request): Builder
    {
        return Activity::query()->with(['causer', 'subject'])->latest('id')
            ->when($request->filled('log'), fn (Builder $q) => $q->where('log_name', $request->string('log')->toString()))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->string('to')->toString()))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';
                $ids = User::withTrashed()->where('email', 'like', $term)->orWhere('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->pluck('id');
                $q->where(fn (Builder $w) => $w->where('description', 'like', $term)
                    ->orWhere(fn (Builder $c) => $c->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $ids))
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', (new User)->getMorphClass())->whereIn('subject_id', $ids)));
            });
    }

    /** @return array{id: int, when: ?string, ago: ?string, log: ?string, event: ?string, description: string, causer: ?string, subject: ?string, ip: ?string, device: ?string, changes: array<string, mixed>} */
    private function present(Activity $a): array
    {
        $props = $a->properties;
        $causer = $a->causer;
        $subject = $a->subject;

        return [
            'id' => (int) $a->id,
            'when' => $a->created_at?->toIso8601String(),
            'ago' => $a->created_at?->diffForHumans(),
            'log' => $a->log_name,
            'event' => $a->event,
            'description' => (string) $a->description,
            'causer' => $causer instanceof User ? $causer->name.' <'.$causer->email.'>' : ($props['email'] ?? 'System'),
            'subject' => $subject instanceof User ? $subject->name : null,
            'ip' => $props['ip'] ?? null,
            'device' => $props['device'] ?? null,
            'changes' => array_filter(['old' => $props['old'] ?? null, 'new' => $props['attributes'] ?? null]),
        ];
    }
}
