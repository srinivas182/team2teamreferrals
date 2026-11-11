<?php

declare(strict_types=1);

namespace App\Domains\Community\Http;

use App\Domains\Community\Events\Events;
use App\Domains\Community\Events\Ics;
use App\Domains\Community\Feed;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class EventController
{
    public function __construct(private readonly Events $events) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $groups = $this->groups($user);
        $rows = DB::table('events as e')->join('groups as g', 'g.id', '=', 'e.group_id')->whereIn('e.group_id', $groups->pluck('id'))
            ->where('e.ends_at', '>=', now()->subDays(30))->orderBy('e.starts_at')->get(['e.*', 'g.name as network']);
        $mine = DB::table('event_rsvps')->where('user_id', $user->id)->pluck('status', 'event_id');

        return Inertia::render('app/Events', [
            'events' => $rows->map(fn ($e) => ['id' => (int) $e->id, 'title' => (string) $e->title, 'network' => (string) $e->network, 'kind' => (string) $e->kind,
                'when' => Events::localTime((string) $e->starts_at, $user->timezone), 'past' => CarbonImmutable::parse((string) $e->ends_at, 'UTC')->isPast(),
                'cancelled' => $e->cancelled_at !== null, 'going' => $this->events->goingCount((int) $e->id), 'capacity' => $e->capacity, 'mine' => $mine[$e->id] ?? null])->values(),
            'ownedGroups' => $groups->filter(fn (Group $g) => $g->isOwnedBy($user) || $user->isStaff())->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->values(),
            'timezone' => $user->timezone,
            'status' => session('status'),
            'seo' => SeoData::private('Events')->toArray(),
        ]);
    }

    public function store(Request $request, Group $group): RedirectResponse
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:140'], 'description' => ['nullable', 'string', 'max:3000'], 'kind' => ['required', 'in:in_person,virtual'],
            'location' => ['nullable', 'required_if:kind,in_person', 'string', 'max:255'], 'video_url' => ['nullable', 'required_if:kind,virtual', 'url:https', 'max:500'],
            'starts_at' => ['required', 'date', 'after:now'], 'minutes' => ['required', 'integer', 'between:15,720'], 'timezone' => ['required', 'timezone:all'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'], 'repeat' => ['nullable', 'in:weekly,monthly'], 'count' => ['nullable', 'integer', 'between:2,12'],
        ]);
        $id = $this->events->create($group, $this->user($request), [
            'title' => (string) $d['title'], 'description' => isset($d['description']) ? (string) $d['description'] : null, 'kind' => (string) $d['kind'],
            'location' => isset($d['location']) ? (string) $d['location'] : null, 'video_url' => isset($d['video_url']) ? (string) $d['video_url'] : null,
            'starts_at' => (string) $d['starts_at'], 'minutes' => (int) $d['minutes'], 'timezone' => (string) $d['timezone'],
            'capacity' => isset($d['capacity']) ? (int) $d['capacity'] : null, 'repeat' => isset($d['repeat']) ? (string) $d['repeat'] : null, 'count' => isset($d['count']) ? (int) $d['count'] : null,
        ]);

        return redirect('/app/events/'.$id)->with('status', 'event-created');
    }

    public function show(Request $request, int $event): Response
    {
        $user = $this->user($request);
        $e = DB::table('events')->where('id', $event)->first();
        abort_if($e === null, 404);
        $group = Group::query()->findOrFail((int) $e->group_id);
        abort_unless(Feed::member($user, $group), 403);
        $isOwner = $group->isOwnedBy($user) || $user->isStaff();
        $rsvps = DB::table('event_rsvps as r')->join('users as u', 'u.id', '=', 'r.user_id')->leftJoin('teams as t', 't.id', '=', 'r.team_id')->where('r.event_id', $event)
            ->orderBy('r.created_at')->get(['r.*', 'u.first_name', 'u.last_name', 't.name as team', 't.slug as team_slug']);
        $mine = $rsvps->firstWhere('user_id', $user->id);
        $ended = CarbonImmutable::parse((string) $e->ends_at, 'UTC')->isPast();
        $anyCheckIns = $rsvps->whereNotNull('checked_in_at')->isNotEmpty();
        $attended = fn ($r) => $anyCheckIns ? $r->checked_in_at !== null : $r->status === 'going';

        return Inertia::render('app/Event', [
            'event' => ['id' => (int) $e->id, 'title' => (string) $e->title, 'description' => $e->description, 'kind' => (string) $e->kind, 'location' => $e->location,
                'cancelled' => $e->cancelled_at !== null, 'ended' => $ended, 'notes' => $e->notes, 'capacity' => $e->capacity, 'network' => $group->name,
                // In the viewer's own zone, with the event's zone alongside.
                'whenLocal' => Events::localTime((string) $e->starts_at, $user->timezone), 'whenEvent' => Events::localTime((string) $e->starts_at, (string) $e->timezone),
                'differentZone' => $user->timezone !== $e->timezone,
                'videoUrl' => Events::canSeeVideo($e, $user) ? $e->video_url : null],
            'mine' => $mine?->status, 'checkedIn' => $mine?->checked_in_at !== null,
            'attendees' => $rsvps->whereIn('status', ['going', 'waitlist'])->map(fn ($r) => ['name' => trim($r->first_name.' '.$r->last_name), 'team' => $r->team, 'status' => (string) $r->status, 'checkedIn' => $r->checked_in_at !== null])->values(),
            'going' => $rsvps->where('status', 'going')->count(),
            // After the event: the other attendees, to follow up with a referral.
            'met' => $ended && $mine !== null && $attended($mine) ? $rsvps->filter(fn ($r) => $r->user_id !== $user->id && $attended($r) && $r->team_slug)->unique('team_slug')
                ->map(fn ($r) => ['team' => (string) $r->team, 'profile' => '/t/'.$r->team_slug])->values() : [],
            'isOwner' => $isOwner,
            'checkInUrl' => $isOwner && $e->kind === 'in_person' ? url('/app/events/'.$e->id.'/check-in/'.$e->check_in_code) : null,
            'status' => session('status'),
            'seo' => SeoData::private((string) $e->title)->toArray(),
        ]);
    }

    public function rsvp(Request $request, int $event): RedirectResponse
    {
        $status = $this->events->rsvp($event, $this->user($request), (string) $request->validate(['status' => ['required', 'in:going,maybe,no']])['status']);

        return back()->with('status', 'rsvp-'.$status);
    }

    public function update(Request $request, int $event): RedirectResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:140'], 'description' => ['nullable', 'string', 'max:3000'], 'starts_at' => ['nullable', 'date', 'after:now'],
            'minutes' => ['nullable', 'integer', 'between:15,720'], 'location' => ['nullable', 'string', 'max:255'], 'video_url' => ['nullable', 'url:https', 'max:500']]);
        $this->events->update($event, $this->user($request), (string) $d['title'], $d['description'] ?? null, $d['starts_at'] ?? null, isset($d['minutes']) ? (int) $d['minutes'] : null, $d['location'] ?? null, $d['video_url'] ?? null);

        return back()->with('status', 'event-updated');
    }

    public function cancel(Request $request, int $event): RedirectResponse
    {
        $this->events->cancel($event, $this->user($request));

        return back()->with('status', 'event-cancelled');
    }

    public function notes(Request $request, int $event): RedirectResponse
    {
        $this->events->notes($event, $this->user($request), (string) $request->validate(['notes' => ['required', 'string', 'max:5000']])['notes']);

        return back()->with('status', 'event-notes');
    }

    public function checkIn(Request $request, int $event, string $code): RedirectResponse
    {
        $this->events->checkIn($event, $code, $this->user($request));

        return redirect('/app/events/'.$event)->with('status', 'checked-in');
    }

    public function ics(Request $request, int $event): \Illuminate\Http\Response
    {
        $e = DB::table('events')->where('id', $event)->first();
        abort_if($e === null || ! Feed::member($this->user($request), Group::query()->findOrFail((int) $e->group_id)), 404);

        return response(Ics::for($e, $e->cancelled_at !== null), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="event-'.$e->id.'.ics"']);
    }

    /** The owner's check-in QR code, shown at the door. */
    public function qr(Request $request, int $event): \Illuminate\Http\Response
    {
        $e = DB::table('events')->where('id', $event)->first();
        abort_if($e === null, 404);
        $group = Group::query()->findOrFail((int) $e->group_id);
        abort_unless($group->isOwnedBy($this->user($request)) || $this->user($request)->isStaff(), 403);

        return response((new Writer(new ImageRenderer(new RendererStyle(360, 1), new SvgImageBackEnd)))->writeString(url('/app/events/'.$e->id.'/check-in/'.$e->check_in_code)), 200, ['Content-Type' => 'image/svg+xml']);
    }

    /** @return \Illuminate\Support\Collection<int, Group> */
    private function groups(User $user): \Illuminate\Support\Collection
    {
        $team = Team::of($user);
        $ids = DB::table('seats')->where('primary_team_id', $team !== null ? $team->id : 0)->pluck('group_id')->merge(DB::table('group_owners')->where('user_id', $user->id)->pluck('group_id'))->unique();

        return Group::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
