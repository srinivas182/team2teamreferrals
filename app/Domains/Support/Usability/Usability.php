<?php

declare(strict_types=1);

namespace App\Domains\Support\Usability;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use stdClass;

/**
 * Usability tests (Sprint 52b). Staff invite a tester and pick an industry family; the tester gets a short script of
 * real tasks written in their industry's words, and a bar at the bottom of the screen walks them through it. While they
 * work, the browser notes where they get stuck: a long time on one page, clicks that do nothing, form errors, giving
 * up or asking for help. Each of those becomes a line on the fix list, counted once however many testers hit it.
 *
 * Only pages, timings, button labels and the names of fields with errors are recorded, never what anyone types.
 */
final class Usability
{
    /** Testers per industry family before the round counts as covered. */
    public const TARGET_PER_FAMILY = 3;

    /** Cookie that marks a browser as running a test (signed, readable by the page so the test bar shows on cached pages). */
    public const COOKIE = 't2t_ut';

    /** Kinds of event, and which of them are problems that belong on the fix list. */
    public const KINDS = ['page', 'stuck', 'rage', 'error', 'help', 'done', 'gave_up'];

    public const PROBLEMS = ['stuck' => 'Stuck', 'rage' => 'Clicks that did nothing', 'error' => 'Form errors', 'help' => 'Asked for help', 'gave_up' => 'Gave up'];

    public const STATUSES = ['open' => 'Open', 'planned' => 'Planned', 'fixed' => 'Fixed', 'wont_fix' => 'Won’t fix'];

    /**
     * The core journeys. {pro}, {client}, {clients}, {referral}, {fee} and {scenario} are filled from the industry.
     * `needs`: 'routing' or 'fees' leaves the journey out for industries whose tier doesn't allow it.
     */
    public const JOURNEYS = [
        'join' => ['title' => 'Join and apply for a seat', 'start' => '/', 'needs' => null,
            'task' => 'You’re {a_pro} and a colleague told you about this site. Join, and apply for the seat in your own city.',
            'done' => 'You’re done when you can see your application waiting for review.'],
        'send' => ['title' => 'Send a {referral}', 'start' => '/app', 'needs' => 'routing',
            'task' => 'One of your {clients} {scenario}. Send them to the {pro} who holds Austin in your network.',
            'done' => 'You’re done when the {referral} shows as sent.'],
        'receive' => ['title' => 'Accept a {referral} sent to you', 'start' => '/app', 'needs' => 'routing',
            'task' => 'Another team has sent you a {client}. Find the {referral} and accept it.',
            'done' => 'You’re done when it shows as accepted and you can see the {client}’s details.'],
        'update' => ['title' => 'Update a {referral}', 'start' => '/app', 'needs' => 'routing',
            'task' => 'You’ve spoken to the {client} you accepted. Move the {referral} to the next stage and add a short note about the call.',
            'done' => 'You’re done when the new stage and your note are saved.'],
        'earnings' => ['title' => 'Check your {fee}s', 'start' => '/app', 'needs' => 'fees',
            'task' => 'Find out how much you’ve been paid in {fee}s this year, and how much you’re still owed.',
            'done' => 'You’re done when you can say both amounts.'],
        'team' => ['title' => 'Invite a teammate', 'start' => '/app', 'needs' => null,
            'task' => 'Invite a colleague to your team so they can work {referral}s with you. Use a made-up address ending in @example.com.',
            'done' => 'You’re done when the invitation shows as sent.'],
        'help' => ['title' => 'Find an answer yourself', 'start' => '/app', 'needs' => null,
            'task' => 'Find out how to change the card you pay with, without contacting anyone.',
            'done' => 'You’re done when you’ve found the steps.'],
    ];

    /** What the "send" task's client needs, in each family's terms. */
    public const SCENARIOS = [
        'property' => 'is moving to Austin, Texas, and wants to buy a home there',
        'home' => 'has bought a house in Austin, Texas, and needs work done on it',
        'money' => 'has moved to Austin, Texas, and needs someone local there',
        'legal' => 'needs help with a matter in Austin, Texas',
        'care' => 'is moving to Austin, Texas, and needs care there',
        'vehicles' => 'needs a vehicle or equipment in Austin, Texas',
        'business' => 'is opening an office in Austin, Texas',
        'life' => 'is planning an event in Austin, Texas',
    ];

    /** The industry a family's script is written for: the one asked for, else a live one that allows the most journeys. */
    public static function industryFor(string $family, ?string $slug = null): ?Industry
    {
        $q = Industry::query()->where('family', $family);
        if ($slug !== null && $slug !== '' && ($i = (clone $q)->where('slug', $slug)->first()) !== null) {
            return $i;
        }

        return $q->get()->sortBy(fn (Industry $i) => [$i->status === 'live' ? 0 : 1, $i->tier->value, $i->name])->first();
    }

    /**
     * The script for a family: each task in the industry's words.
     *
     * @param  list<string>|null  $only  journey keys to keep, in their standard order
     * @return array{family: string, familyName: string, industry: string|null, tasks: list<array{key: string, title: string, task: string, done: string, start: string}>}
     */
    public static function script(string $family, ?string $slug = null, ?array $only = null): array
    {
        $industry = self::industryFor($family, $slug);
        $terms = $industry !== null ? $industry->terms() : ['pro' => 'professional', 'pros' => 'professionals', 'client' => 'client', 'clients' => 'clients', 'referral' => 'referral', 'fee' => 'referral fee'];
        $tier = $industry !== null ? $industry->tier : Tier::Open;
        $fill = fn (string $s): string => Str::ucfirst(strtr($s, ['{a_pro}' => (preg_match('/^([aeiouAEIOU]|[FHLMNRSX][A-Z])/', $terms['pro']) === 1 && ! preg_match('/^(uni|eu|one)/i', $terms['pro']) ? 'an ' : 'a ').$terms['pro'], '{pro}' => $terms['pro'], '{client}' => $terms['client'], '{clients}' => $terms['clients'],
            '{referral}' => $terms['referral'], '{fee}' => $terms['fee'], '{scenario}' => self::SCENARIOS[$family] ?? self::SCENARIOS['property']]));
        $tasks = [];
        foreach (self::JOURNEYS as $key => $j) {
            $allowed = match ($j['needs']) {
                'routing' => $tier->allowsReferralRouting(),
                'fees' => $tier->allowsMemberReferralFees(),
                default => true,
            };
            if ($allowed && ($only === null || in_array($key, $only, true))) {
                $tasks[] = ['key' => $key, 'title' => $fill($j['title']), 'task' => $fill($j['task']), 'done' => $fill($j['done']), 'start' => $j['start']];
            }
        }

        return ['family' => $family, 'familyName' => Industry::FAMILIES[$family] ?? $family, 'industry' => $industry?->name, 'tasks' => $tasks];
    }

    /**
     * Invite a tester: saves the session and emails them a link to start (valid for 14 days).
     *
     * @param  list<string>  $journeys
     */
    public static function invite(string $name, string $email, string $family, ?string $industrySlug, array $journeys, ?User $by): stdClass
    {
        $script = self::script($family, $industrySlug, $journeys);
        $id = DB::table('usability_sessions')->insertGetId([
            'ulid' => strtolower((string) Str::ulid()), 'name' => $name, 'email' => mb_strtolower($email), 'family' => $family,
            'industry_slug' => self::industryFor($family, $industrySlug)?->slug, 'journeys' => json_encode(array_column($script['tasks'], 'key')),
            'status' => 'invited', 'invited_at' => now(), 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $s = self::find((int) $id);
        Notification::route('mail', $s->email)->notify(new UsabilityInvite($s->name, self::startUrl($s), count($script['tasks']), $script['industry']));

        return $s;
    }

    public static function startUrl(stdClass $s): string
    {
        return URL::temporarySignedRoute('usability.welcome', now()->addDays(14), ['session' => $s->ulid]);
    }

    public static function find(int|string $idOrUlid): stdClass
    {
        $s = DB::table('usability_sessions')->where(is_int($idOrUlid) ? 'id' : 'ulid', $idOrUlid)->first();
        abort_if($s === null, 404);

        return $s;
    }

    /**
     * The tasks for a session, in order.
     *
     * @return list<array{key: string, title: string, task: string, done: string, start: string}>
     */
    public static function tasks(stdClass $s): array
    {
        return self::script((string) $s->family, $s->industry_slug, self::journeyKeys($s))['tasks'];
    }

    /**
     * The journey keys a session was invited to do.
     *
     * @return list<string>
     */
    public static function journeyKeys(stdClass $s): array
    {
        return array_values(array_filter((array) json_decode((string) $s->journeys, true), 'is_string'));
    }

    /** The signed cookie value that ties a browser to a test session. */
    public static function token(stdClass $s): string
    {
        return $s->ulid.'.'.substr(hash_hmac('sha256', 'usability|'.$s->ulid, (string) config('app.key')), 0, 16);
    }

    /** The running session a cookie points at, if the signature matches and the test isn't finished. */
    public static function fromToken(?string $token): ?stdClass
    {
        if ($token === null || ! preg_match('/^([0-9a-z]{26})\.([0-9a-f]{16})$/', $token, $m)) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', 'usability|'.$m[1], (string) config('app.key')), 0, 16);
        if (! hash_equals($expected, $m[2])) {
            return null;
        }
        $s = DB::table('usability_sessions')->where('ulid', $m[1])->first();

        return $s !== null && $s->status === 'started' ? $s : null;
    }

    public static function start(stdClass $s): stdClass
    {
        if ($s->status === 'invited') {
            DB::table('usability_sessions')->where('id', $s->id)->update(['status' => 'started', 'started_at' => now(), 'updated_at' => now()]);
        }

        return self::find((int) $s->id);
    }

    /**
     * Record events from the browser. Problems go on the fix list; "done" and "gave up" also move the tester on.
     *
     * @param  list<array{kind?: mixed, path?: mixed, detail?: mixed, seconds?: mixed, task?: mixed}>  $events
     */
    public static function record(stdClass $s, array $events): int
    {
        $tasks = array_column(self::tasks($s), 'key');
        $n = 0;
        foreach (array_slice($events, 0, 50) as $e) {
            $kind = (string) ($e['kind'] ?? '');
            if (! in_array($kind, self::KINDS, true)) {
                continue;
            }
            $given = $e['task'] ?? null;
            $task = is_string($given) && in_array($given, $tasks, true) ? $given : ($tasks[$s->current] ?? null);
            $path = self::cleanPath((string) ($e['path'] ?? '/'));
            $detail = isset($e['detail']) && is_string($e['detail']) && trim($e['detail']) !== '' ? mb_substr(trim(strip_tags($e['detail'])), 0, 500) : null;
            $seconds = isset($e['seconds']) && is_numeric($e['seconds']) ? max(0, min(86400, (int) $e['seconds'])) : null;
            DB::table('usability_events')->insert(['session_id' => $s->id, 'task' => $task, 'kind' => $kind, 'path' => $path, 'detail' => $detail, 'seconds' => $seconds, 'created_at' => now()]);
            $n++;
            if (isset(self::PROBLEMS[$kind])) {
                self::flag($s, $kind, $task, $path, $detail);
            }
            if (in_array($kind, ['done', 'gave_up'], true) && $task !== null) {
                $s = self::advance($s, $task, $kind, $seconds, $detail);
            }
        }

        return $n;
    }

    /** Mark a task done or given up and move to the next one; after the last task the test is finished. */
    private static function advance(stdClass $s, string $task, string $outcome, ?int $seconds, ?string $note): stdClass
    {
        $results = (array) json_decode((string) ($s->results ?? '{}'), true);
        $results[$task] = ['outcome' => $outcome, 'seconds' => $seconds, 'note' => $note];
        $keys = array_column(self::tasks($s), 'key');
        $next = 0;
        while ($next < count($keys) && isset($results[$keys[$next]])) {
            $next++;
        }
        DB::table('usability_sessions')->where('id', $s->id)->update(['results' => json_encode($results), 'current' => min($next, 255), 'updated_at' => now(),
            ...($next >= count($keys) ? ['status' => 'finished', 'finished_at' => now()] : [])]);

        return self::find((int) $s->id);
    }

    public static function finish(stdClass $s): void
    {
        DB::table('usability_sessions')->where('id', $s->id)->where('status', '!=', 'finished')->update(['status' => 'finished', 'finished_at' => now(), 'updated_at' => now()]);
    }

    /** Add a problem to the fix list, or count it again. Severity rises with how many testers hit it. */
    private static function flag(stdClass $s, string $kind, ?string $task, string $path, ?string $detail): void
    {
        $pattern = self::pattern($path);
        $fingerprint = hash('sha256', $kind.'|'.$task.'|'.$pattern);
        $issue = DB::table('usability_issues')->where('fingerprint', $fingerprint)->first();
        $testers = $issue !== null ? (array) json_decode((string) $issue->testers, true) : [];
        $families = $issue !== null ? (array) json_decode((string) $issue->families, true) : [];
        $testers = array_values(array_unique([...$testers, (int) $s->id]));
        $families = array_values(array_unique([...$families, (string) $s->family]));
        $severity = match (true) {
            $kind === 'gave_up' || count($testers) >= self::TARGET_PER_FAMILY => 'high',
            $kind === 'rage' && count($testers) < 2 => 'low',
            default => 'medium',
        };
        $journey = $task !== null && isset(self::JOURNEYS[$task]) ? Str::of(self::JOURNEYS[$task]['title'])->replace(['{referral}', '{fee}'], ['referral', 'referral fee'])->toString() : 'Any task';
        $title = self::PROBLEMS[$kind].' on '.$pattern.' · '.$journey.($kind === 'rage' && $detail !== null ? ' ('.mb_substr($detail, 0, 60).')' : '');
        $values = ['title' => mb_substr($title, 0, 200), 'task' => $task, 'kind' => $kind, 'path' => $pattern, 'testers' => json_encode($testers), 'families' => json_encode($families), 'last_seen_at' => now(), 'updated_at' => now()];
        if ($issue === null) {
            DB::table('usability_issues')->insert([...$values, 'fingerprint' => $fingerprint, 'severity' => $severity, 'hits' => 1, 'created_at' => now()]);

            return;
        }
        // A fixed problem that comes back is open again.
        $reopen = $issue->status === 'fixed' ? ['status' => 'open'] : [];
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
        DB::table('usability_issues')->where('id', $issue->id)->update([...$values, ...$reopen, 'hits' => $issue->hits + 1,
            'severity' => $rank[$severity] > $rank[(string) $issue->severity] ? $severity : $issue->severity]);
    }

    /** A path without its query, and with ids replaced, so "/app/referrals/01H…" groups with every other referral. */
    public static function pattern(string $path): string
    {
        $path = self::cleanPath($path);
        $path = (string) preg_replace('#/[0-9a-z]{26}(?=/|$)#i', '/{id}', $path);
        $path = (string) preg_replace('#/\d+(?=/|$)#', '/{id}', $path);

        return $path === '' ? '/' : $path;
    }

    private static function cleanPath(string $path): string
    {
        $p = (string) parse_url($path, PHP_URL_PATH);
        $p = '/'.ltrim((string) preg_replace('#[^A-Za-z0-9/_\-.{}]#', '', $p), '/');

        return mb_substr($p, 0, 300);
    }

    /**
     * How far each family has got towards the target number of finished testers.
     *
     * @return list<array{family: string, name: string, invited: int, finished: int, target: int}>
     */
    public static function coverage(): array
    {
        $rows = DB::table('usability_sessions')->groupBy('family')->selectRaw("family, count(*) as invited, sum(case when status = 'finished' then 1 else 0 end) as finished")->get()->keyBy('family');

        return array_map(fn ($key, $name) => ['family' => $key, 'name' => $name, 'invited' => (int) ($rows[$key]->invited ?? 0),
            'finished' => (int) ($rows[$key]->finished ?? 0), 'target' => self::TARGET_PER_FAMILY], array_keys(Industry::FAMILIES), Industry::FAMILIES);
    }
}
