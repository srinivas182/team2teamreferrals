<?php

declare(strict_types=1);

namespace App\Domains\Support\Usability\Http;

use App\Domains\Seo\SeoData;
use App\Domains\Support\Usability\Usability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/** The tester's side of a usability test: the welcome page from the emailed link, the test bar's state and its events. */
final class UsabilityController
{
    /** From the emailed (signed) link: what the test involves, what's recorded, and the button to begin. */
    public function welcome(Request $request): Response
    {
        $s = Usability::find((string) $request->route('session'));
        $script = Usability::script((string) $s->family, $s->industry_slug, Usability::journeyKeys($s));

        return Inertia::render('usability/Welcome', [
            'name' => explode(' ', trim((string) $s->name))[0],
            'industry' => $script['industry'],
            'tasks' => array_map(fn ($t) => ['title' => $t['title'], 'task' => $t['task']], $script['tasks']),
            'finished' => $s->status === 'finished',
            'beginUrl' => URL::temporarySignedRoute('usability.begin', now()->addDay(), ['session' => $s->ulid]),
            'seo' => SeoData::private('Usability test')->toArray(),
        ]);
    }

    /** Start (or resume) the test in this browser and go to the first unfinished task. */
    public function begin(Request $request): RedirectResponse
    {
        $s = Usability::find((string) $request->route('session'));
        if ($s->status === 'finished') {
            return redirect()->route('usability.thanks');
        }
        $s = Usability::start($s);
        $task = Usability::tasks($s)[$s->current] ?? null;
        // Readable by the page (not HttpOnly) so the test bar shows on cached pages; the value is signed, not secret.
        Cookie::queue(Cookie::make(Usability::COOKIE, Usability::token($s), 60 * 24 * 7, '/', null, null, false, false, 'lax'));

        return redirect($task['start'] ?? '/');
    }

    /** The test bar's state: the tasks, which one the tester is on, and how the others went. */
    public function state(Request $request): JsonResponse
    {
        $s = Usability::fromToken(self::cookie($request));
        if ($s === null) {
            return response()->json(['active' => false])->withCookie(Cookie::forget(Usability::COOKIE));
        }

        return response()->json([
            'active' => true,
            'tasks' => array_map(fn ($t) => ['key' => $t['key'], 'title' => $t['title'], 'task' => $t['task'], 'done' => $t['done'], 'start' => $t['start']], Usability::tasks($s)),
            'current' => (int) $s->current,
            'results' => (object) array_map(fn ($r) => $r['outcome'] ?? null, (array) json_decode((string) ($s->results ?? '{}'), true)),
        ])->header('Cache-Control', 'no-store');
    }

    /** Events from the test bar (sent with sendBeacon, so no CSRF token; the signed cookie identifies the test). */
    public function events(Request $request): JsonResponse
    {
        $s = Usability::fromToken(self::cookie($request));
        if ($s === null) {
            return response()->json(['active' => false], 404);
        }
        $data = $request->validate(['events' => ['required', 'array', 'max:50'], 'events.*' => ['array']]);
        Usability::record($s, array_values($data['events']));
        $s = Usability::find((int) $s->id);

        return response()->json(['active' => $s->status === 'started', 'current' => (int) $s->current, 'finished' => $s->status === 'finished']);
    }

    /** Stop testing in this browser: the test is marked finished and the bar disappears. */
    public function stop(Request $request): RedirectResponse
    {
        $s = Usability::fromToken(self::cookie($request));
        if ($s !== null) {
            Usability::finish($s);
        }

        return redirect()->route('usability.thanks')->withCookie(Cookie::forget(Usability::COOKIE));
    }

    private static function cookie(Request $request): ?string
    {
        $value = $request->cookie(Usability::COOKIE);

        return is_string($value) ? $value : null;
    }

    public function thanks(): Response
    {
        return Inertia::render('usability/Thanks', ['seo' => SeoData::private('Thank you')->toArray()]);
    }
}
