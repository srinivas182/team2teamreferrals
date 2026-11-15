<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Integrations\Calendar\CalendarConnection;
use App\Domains\Integrations\Calendar\CalendarFeed;
use App\Domains\Integrations\Calendar\CalendarItems;
use App\Domains\Integrations\Calendar\CalendarSync;
use App\Domains\Integrations\Providers\CrmException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Each person's calendar: the private calendar link, and Google or Microsoft calendar sync. */
final class CalendarController
{
    /** The private calendar link (no sign-in: the long random token is the key). */
    public function feed(string $token): Response
    {
        $user = CalendarFeed::userFor($token) ?? abort(404);

        return response(CalendarFeed::ics($user), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=900', 'X-Robots-Tag' => 'noindex']);
    }

    public function createLink(Request $request): RedirectResponse
    {
        CalendarFeed::create($this->user($request));

        return back()->with('status', 'calendar-link-created');
    }

    public function removeLink(Request $request): RedirectResponse
    {
        CalendarFeed::remove($this->user($request));

        return back()->with('status', 'calendar-link-removed');
    }

    /** Off to Google or Microsoft to approve access. */
    public function connect(Request $request, string $provider): Response
    {
        $p = CalendarSync::provider($provider);
        abort_unless($p->ready(), 404);
        $state = Str::random(40);
        $request->session()->put('calendar_oauth', ['state' => $state, 'provider' => $provider]);

        return \Inertia\Inertia::location($p->authUrl($state));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $saved = (array) $request->session()->pull('calendar_oauth', []);
        if (($saved['provider'] ?? null) !== $provider || ! hash_equals((string) ($saved['state'] ?? ''), (string) $request->query('state', ''))) {
            return redirect('/app/integrations')->withErrors(['calendar' => 'That sign-in link expired. Please try connecting again.']);
        }
        if ($request->query('error') !== null || ! is_string($request->query('code'))) {
            return redirect('/app/integrations')->withErrors(['calendar' => 'Calendar access wasn’t approved, so nothing was connected.']);
        }
        try {
            CalendarSync::connect($this->user($request), $provider, (string) $request->query('code'));
        } catch (CrmException $e) {
            return redirect('/app/integrations')->withErrors(['calendar' => $e->getMessage()]);
        }

        return redirect('/app/integrations')->with('status', 'calendar-connected');
    }

    /** The person's writable calendars, for the picker. */
    public function calendars(Request $request, string $connection): JsonResponse
    {
        return response()->json(CalendarSync::calendars($this->connection($request, $connection)));
    }

    public function update(Request $request, string $connection): RedirectResponse
    {
        $c = $this->connection($request, $connection);
        $d = $request->validate(['calendar_id' => ['sometimes', 'string', 'max:255'], 'kinds' => ['present', 'array'], 'kinds.*' => ['in:'.implode(',', array_keys(CalendarItems::KINDS))]]);
        $name = null;
        if (isset($d['calendar_id']) && $d['calendar_id'] !== $c->calendar_id) {
            $match = collect(CalendarSync::calendars($c))->firstWhere('id', $d['calendar_id']);
            abort_if($match === null, 422, 'Pick one of your calendars.');
            $name = $match['name'];
        }
        CalendarSync::update($c, $d['calendar_id'] ?? null, $name, array_values((array) $d['kinds']));

        return back()->with('status', 'calendar-updated');
    }

    public function syncNow(Request $request, string $connection): RedirectResponse
    {
        $r = CalendarSync::sync($this->connection($request, $connection));

        return $r === null ? back()->withErrors(['calendar' => 'Your calendar couldn’t be reached. See the message on the connection.']) : back()->with('status', 'calendar-synced');
    }

    public function disconnect(Request $request, string $connection): RedirectResponse
    {
        CalendarSync::disconnect($this->connection($request, $connection));

        return back()->with('status', 'calendar-disconnected');
    }

    private function connection(Request $request, string $id): CalendarConnection
    {
        return CalendarConnection::query()->where(['ulid' => $id, 'user_id' => $this->user($request)->id])->firstOrFail();
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
