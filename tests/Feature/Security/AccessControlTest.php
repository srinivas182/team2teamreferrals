<?php

/*
 * Sprint 53a security review: access control and data isolation across every route.
 *
 * 1. Static rules over the route table: every member route needs a signed-in, active account; every admin route also
 *    needs staff and a named permission (a few are open to all staff on purpose); every request that changes something
 *    without signing in is either protected against cross-site requests, or rate limited and checked another way
 *    (signature, webhook secret, signed cookie).
 * 2. A sweep with real data (the demo workspace): an outsider (a verified member of another team, in no network) calls
 *    every member route that takes an id, with the ids of other people's records. None may show or change anything.
 * 3. Staff without a permission are refused every admin screen that needs it.
 */

use App\Domains\Content\Demo\DemoWorkspace;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Admin routes any staff member may open (their own dashboard and search, with nothing sensitive). */
const ADMIN_OPEN_TO_ALL_STAFF = ['admin', 'admin/search'];

/**
 * Requests that change something without signing in or a CSRF token, and why that's safe. Each must also be rate limited.
 * Keys are route URIs; anything not listed here must keep CSRF protection.
 */
const CSRF_EXEMPT = [
    'visit' => 'first-party visit attribution; no account changes',
    'partners/seen' => 'counts a partner listing view',
    'help/{slug}/seen' => 'counts an article view',
    'help/{slug}/feedback' => 'records "was this helpful"; no account changes',
    'growth/unsubscribe/{prospect}' => 'one-click unsubscribe (RFC 8058); signed link',
    'usability/events' => 'usability test events; the signed test cookie identifies the test',
];

/** @return list<string> */
function middlewareOf(RoutingRoute $route): array
{
    return array_map(fn ($m) => is_string($m) ? $m : get_class($m), Route::gatherRouteMiddleware($route));
}

function routeHas(array $middleware, string $needle): bool
{
    foreach ($middleware as $m) {
        if ($m === $needle || str_starts_with($m, $needle.':') || str_ends_with($m, '\\'.$needle)) {
            return true;
        }
    }

    return false;
}

it('requires a signed-in active account on every member route, and staff plus a permission on admin routes', function () {
    $problems = [];
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $mw = middlewareOf($route);
        $auth = routeHas($mw, 'Illuminate\Auth\Middleware\Authenticate') || routeHas($mw, 'auth');
        if (str_starts_with($uri, 'app/') || $uri === 'app') {
            // Calendar sign-in callbacks come back from Google or Microsoft and check their own state.
            if (! $auth && ! str_ends_with($uri, '/callback')) {
                $problems[] = "member route without sign-in: {$uri}";
            }
            if ($auth && ! routeHas($mw, 'App\Domains\Identity\Http\Middleware\EnsureAccountIsActive') && ! in_array($uri, ['app/tours/{tour}/done', 'app/tours/replay', 'app/tours/tips'], true)) {
                $problems[] = "member route without the active-account check: {$uri}";
            }
        }
        if (str_starts_with($uri, 'admin/') || $uri === 'admin') {
            if (! $auth || ! routeHas($mw, 'App\Domains\Identity\Http\Middleware\EnsureStaff')) {
                $problems[] = "admin route without staff sign-in: {$uri}";
            } elseif (! in_array($uri, ADMIN_OPEN_TO_ALL_STAFF, true) && ! collect($mw)->contains(fn ($m) => str_starts_with($m, 'can:') || str_contains($m, 'PermissionMiddleware') || str_contains($m, 'RoleMiddleware') || str_starts_with($m, 'permission:') || str_starts_with($m, 'role:'))) {
                $problems[] = "admin route open to every staff member: {$uri}";
            }
        }
    }
    expect($problems)->toBe([]);
});

it('keeps CSRF protection on every web request that changes something, except a short reviewed list that is rate limited', function () {
    $exempt = [];
    $unthrottled = [];
    foreach (Route::getRoutes() as $route) {
        $methods = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
        $mw = middlewareOf($route);
        if ($methods === [] || ! routeHas($mw, 'web')) {
            continue;
        }
        if (in_array(ValidateCsrfToken::class, $route->excludedMiddleware(), true)) {
            $exempt[] = $route->uri();
            if (! collect($mw)->contains(fn ($m) => str_starts_with($m, 'throttle') || str_contains($m, 'ThrottlePerRoute'))) {
                $unthrottled[] = $route->uri();
            }
        }
    }
    sort($exempt);
    $listed = array_keys(CSRF_EXEMPT);
    sort($listed);
    expect($exempt)->toBe($listed)->and($unthrottled)->toBe([]);
});

it('rate limits every request that changes something without signing in', function () {
    $open = [];
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $mw = middlewareOf($route);
        // Horizon's screens sit behind its own sign-in gate; Laravel serves and receives private files only with a signed URL.
        if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [] || routeHas($mw, 'auth') || routeHas($mw, 'Illuminate\Auth\Middleware\Authenticate')
            || routeHas($mw, 'Laravel\Horizon\Http\Middleware\Authenticate') || $uri === 'storage/{path}'
            || str_starts_with($uri, 'admin') || str_starts_with($uri, 'app/') || str_starts_with($uri, '_') || str_starts_with($uri, 'livewire')) {
            continue;
        }
        $throttled = collect($mw)->contains(fn ($m) => str_starts_with($m, 'throttle') || str_contains($m, 'ThrottlePerRoute') || str_contains($m, 'ThrottleRequests'));
        // Fortify's sign-in, two-step and password routes use its own named limiters.
        $fortify = str_contains((string) $route->getActionName(), 'Laravel\\Fortify');
        // Webhooks from providers are verified by signature and must accept bursts.
        $webhook = str_starts_with($uri, 'webhooks/') || str_starts_with($uri, 'api/webhooks/') || str_contains($uri, 'stripe/webhook');
        if (! $throttled && ! $fortify && ! $webhook) {
            $open[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$uri;
        }
    }
    expect($open)->toBe([]);
});

/**
 * A row in a table, owned by the given user and team, with every required column filled with something valid. Used to
 * give the sweep a record of every kind the demo workspace doesn't make (calendar links, webhooks, invoices, …).
 */
function seedRowFor(string $table, User $owner, int $teamId): void
{
    if (! Schema::hasTable($table) || DB::table($table)->exists()) {
        return;
    }
    $special = ['report_id' => 'saved_reports', 'endpoint_id' => 'webhook_endpoints', 'connection_id' => 'calendar_connections', 'created_by' => 'users', 'owner_id' => 'users',
        'decided_by' => 'users', 'invited_by' => 'users', 'challenger_team_id' => 'teams', 'holder_team_id' => 'teams', 'from_team_id' => 'teams', 'to_team_id' => 'teams', 'billable_id' => 'teams'];
    $row = [];
    foreach (Schema::getColumns($table) as $c) {
        $name = $c['name'];
        if ($c['auto_increment'] || $c['nullable'] || $c['default'] !== null) {
            if (! in_array($name, ['user_id', 'team_id', 'ulid'], true)) {
                continue;
            }
        }
        $type = strtolower((string) $c['type_name']);
        $row[$name] = match (true) {
            $name === 'user_id' => $owner->id,
            $name === 'team_id' => $teamId,
            $name === 'ulid' => strtolower((string) Illuminate\Support\Str::ulid()),
            str_ends_with($name, '_id') || isset($special[$name]) => (function () use ($name, $special) {
                $t = $special[$name] ?? Illuminate\Support\Str::plural(substr($name, 0, -3));
                $t = $t === 'groups' || Schema::hasTable($t) ? $t : null;

                return $t !== null ? (DB::table($t)->orderBy('id')->value('id') ?? 1) : 1;
            })(),
            str_contains($type, 'int') => 1,
            str_contains($type, 'bool') => 0,
            str_contains($type, 'json') => '{}',
            str_contains($type, 'date') || str_contains($type, 'time') => now(),
            str_contains($type, 'dec') || str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'numeric') => 1,
            default => 'x'.random_int(1000, 9999),
        };
    }
    try {
        DB::table($table)->insert($row);
    } catch (Throwable) {
        // a table with rules this can't satisfy: the sweep just skips its routes
    }
}

/** Values to try for each route parameter: real ids, ulids and slugs of other people's records. */
function candidates(string $param, string $uri): array
{
    $tables = [
        'referral' => 'referrals', 'fee' => 'referral_fees', 'event' => 'events', 'group' => 'groups', 'ticket' => 'support_tickets', 'invoice' => 'invoices',
        'seat' => 'seats', 'prospect' => 'prospects', 'post' => 'feed_posts', 'attachment' => 'referral_attachments', 'connection' => 'calendar_connections',
        'delivery' => 'webhook_deliveries', 'endpoint' => 'webhook_endpoints', 'draft' => 'referral_drafts', 'integration' => 'integrations',
        'invite' => 'team_invites', 'license' => 'team_licenses', 'lead' => 'profile_leads', 'offer' => 'seat_offers', 'report' => 'saved_reports',
        'schedule' => 'report_schedules', 'script' => 'video_scripts', 'task' => 'prospect_tasks', 'testimonial' => 'testimonials', 'token' => 'api_tokens',
        'view' => str_contains($uri, 'prospects') ? 'prospect_views' : 'dashboard_views', 'message' => 'support_messages', 'application' => 'seat_applications',
        'member' => 'users', 'device' => 'user_devices', 'intake' => 'intake_messages', 'ulid' => 'consumer_requests',
        'id' => match (true) {
            str_contains($uri, 'challenges') => 'seat_challenges', str_contains($uri, 'notifications') => 'notifications', str_contains($uri, 'time-off') => 'team_time_off',
            str_contains($uri, 'feed/') => 'feed_posts', default => 'referrals',
        },
    ];
    $fixed = [
        'type' => ['team', 'company'], 'kpi' => ['referrals_sent'], 'year' => [(string) now()->year], 'provider' => ['google'], 'target' => ['post'],
        'action' => ['approve', 'pause', 'publish'], 'key' => ['summary'], 'code' => ['x'], 'index' => ['0'], 'page' => ['overview'], 'tour' => ['member-home'],
        'industry' => ['solar'], 'slug' => ['stage-right-austin'], 'section' => ['overview'],
    ];
    if (isset($fixed[$param])) {
        return $fixed[$param];
    }
    $table = $tables[$param] ?? null;
    if ($table === null || ! Schema::hasTable($table)) {
        return [];
    }
    $row = DB::table($table)->orderBy('id')->first();
    if ($row === null) {
        return [];
    }

    return array_values(array_unique(array_filter([(string) $row->id, isset($row->ulid) ? (string) $row->ulid : null, isset($row->slug) ? (string) $row->slug : null])));
}

it('lets no member see or change another team’s records through any member route', function () {
    Mail::fake();
    Notification::fake();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    DemoWorkspace::create();
    // Records the demo doesn't make: a support ticket, a prospect, an invoice and webhook settings.
    $victim = User::query()->where('email', 'demo2@'.DemoWorkspace::DOMAIN)->firstOrFail();
    App\Domains\Support\Tickets::open(['name' => 'V', 'email' => $victim->email, 'subject' => 'Invoice', 'body' => 'A question about my invoice please', 'category' => 'billing'], $victim);

    $victimTeam = (int) DB::table('teams')->where('leader_id', $victim->id)->value('id');
    foreach (['user_devices', 'notifications', 'seat_offers', 'team_invites', 'referral_attachments', 'dashboard_views', 'intake_messages', 'referral_drafts', 'team_time_off',
        'seat_challenges', 'profile_leads', 'video_scripts', 'saved_reports', 'report_schedules', 'consumer_requests', 'invoices', 'api_tokens', 'webhook_endpoints',
        'webhook_deliveries', 'integrations', 'calendar_connections', 'prospects', 'prospect_views', 'prospect_tasks', 'testimonials'] as $t) {
        seedRowFor($t, $victim, $victimTeam);
    }

    $outsider = User::factory()->create(['email_verified_at' => now(), 'phone' => '+14355550199', 'phone_verified_at' => now()]);
    Team::forLeader($outsider);
    // And the owner of a different network, who must not reach into this one.
    $otherOwner = User::factory()->create(['email_verified_at' => now(), 'phone' => '+14355550198', 'phone_verified_at' => now()]);
    Team::forLeader($otherOwner);
    $firstGroup = (int) DB::table('groups')->orderBy('id')->value('id');
    DB::table('group_owners')->insert(['group_id' => (int) DB::table('groups')->where('id', '!=', $firstGroup)->orderByDesc('id')->value('id'), 'user_id' => $otherOwner->id, 'created_at' => now(), 'updated_at' => now()]);

    // Routes that show network-wide information any signed-in member may see (no other team's private records).
    $shared = ['app/events/{event}', 'app/events/{event}.ics', 'app/events/{event}/qr.svg', 'app/groups/{group}/apply', 'app/training/{industry}', 'app/training/{industry}/refresher',
        'app/dashboard/kpi/{kpi}', 'app/dashboard/kpi/{kpi}/export.csv', 'app/owner/{page?}', 'app/calendar/{provider}/callback', 'app/tours/{tour}/done', 'app/partners/{slug}/share',
        // These act only on the caller's own records: their training progress, their own yearly statement and their own billing.
        'app/training/{industry}/lessons/{index}', 'app/training/{industry}/quiz', 'app/training/{industry}/certificate.pdf', 'app/earnings/{year}.pdf',
        'app/billing/{type}/setup-intent', 'app/billing/{type}/card', 'app/billing/{type}/coupon'];

    $leaks = [];
    $skipped = [];
    $checked = 0;
    foreach (['outsider' => $outsider, 'owner of another network' => $otherOwner] as $who => $actor) {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'app/') || ! str_contains($uri, '{') || in_array($uri, $shared, true)) {
                continue;
            }
            preg_match_all('/\{(\w+)\??\}/', $uri, $m);
            $sets = [];
            foreach ($m[1] as $p) {
                $sets[$p] = candidates($p, $uri);
            }
            if (in_array([], $sets, true)) {
                $skipped[] = $uri;

                continue; // nothing of that kind exists to try
            }
            $width = max(array_map('count', $sets));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                for ($i = 0; $i < $width; $i++) {
                    $url = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($x) => rawurlencode($sets[$x[1]][min($i, count($sets[$x[1]]) - 1)]), $uri);
                    // A recently confirmed password, so screens that ask for it again show what's behind them.
                    $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()]);
                    $response = $this->json($method, $url, [], ['REMOTE_ADDR' => '10.9.'.intdiv($checked, 250).'.'.($checked % 250)]);
                    $checked++;
                    $status = $response->getStatusCode();
                    $refused = in_array($status, [401, 403, 404, 405, 409, 410, 419, 422, 429, 503], true) // 503: payments not set up, refused before anything happens
                        || ($status === 302 && (session()->has('errors') || preg_match('#/(login|verify|app/?$)#', (string) $response->headers->get('Location')) === 1));
                    if (! $refused) {
                        $leaks[] = "{$who}: {$method} {$url} → {$status}";
                    }
                    session()->forget('errors');
                }
            }
        }
    }
    if (getenv('SHOW_SKIPPED')) {
        fwrite(STDERR, implode(PHP_EOL, array_unique($skipped)).PHP_EOL);
    }
    expect($checked)->toBeGreaterThan(400)->and($leaks)->toBe([]);
})->group('slow');

it('refuses staff every admin screen whose permission they lack', function () {
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(Permissions::STAFF);
    $allowed = [];
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'admin') || str_contains($uri, '{') || ! in_array('GET', $route->methods(), true) || in_array($uri, ADMIN_OPEN_TO_ALL_STAFF, true)) {
            continue;
        }
        $status = $this->actingAs($staff)->get('/'.$uri)->getStatusCode();
        if ($status < 300) {
            $allowed[] = $uri;
        }
    }
    expect($allowed)->toBe([]);
    // Members (not staff) are refused the admin entirely.
    $member = User::factory()->create();
    expect($this->actingAs($member)->get('/admin')->getStatusCode())->toBeIn([302, 403, 404]);
});
