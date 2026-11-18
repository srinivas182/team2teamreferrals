<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        // Portal and admin: after load, fetch the other screens' scripts (three at a time) so the next click is instant.
        // Public pages: no, a visitor on a phone shouldn't download the whole app (Core Web Vitals, Sprint 43). Set on
        // every request because the Vite instance is shared between requests under Octane.
        \Illuminate\Support\Facades\Vite::usePrefetchStrategy($request->is('app', 'app/*', 'admin', 'admin/*') ? 'waterfall' : null, ['concurrency' => 3]);

        // The member's team, looked up once per request and shared by the props below.
        $team = false;
        $teamOf = function () use ($request, &$team): ?\App\Domains\Network\Seats\Models\Team {
            if ($team === false) {
                $u = $request->user();
                $team = $u instanceof \App\Domains\Identity\Models\User ? \App\Domains\Network\Seats\Models\Team::of($u) : null;
            }

            return $team;
        };

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => ($u = $request->user()) instanceof \App\Domains\Identity\Models\User ? [
                    'id' => $u->ulid,
                    'name' => $u->name,
                    'firstName' => $u->first_name,
                    'email' => $u->email,
                    'isStaff' => $u->isStaff(),
                    // Admin navigation shows only what this person may open.
                    'permissions' => $u->isStaff()
                        ? ($u->hasRole(\App\Domains\Identity\Authorization\Permissions::SUPER_ADMIN) ? \App\Domains\Identity\Authorization\Permissions::all() : $u->getAllPermissions()->pluck('name')->values()->all())
                        : [],
                ] : null,
            ],
            'flash' => ['status' => fn () => $request->session()->get('status'), 'warnings' => fn () => $request->session()->get('warnings')],
            // First-run guided tours (Sprint 52a): finished ones, and whether tips are off.
            'tours' => fn () => ($u = $request->user()) instanceof \App\Domains\Identity\Models\User ? \App\Domains\Platform\Tours\ToursController::state($u) : null,
            // A demo account (Sprint 52a): the portal shows a banner, and the reset time.
            'demo' => fn () => ($u = $request->user()) !== null && str_ends_with((string) $u->email, '@'.\App\Domains\Content\Demo\DemoWorkspace::DOMAIN),
            // The installable app (Sprint 53b): offer to install only after a member's first referral. Checked at most
            // every ten minutes per session, so it costs nothing on most requests.
            'pwa' => fn () => $request->user() === null ? null : ['sent' => (function () use ($request): bool {
                $known = $request->session()->get('pwa.sent');
                if (is_array($known) && ($known['v'] === true || $known['at'] > now()->getTimestamp() - 600)) {
                    return (bool) $known['v'];
                }
                $sent = \Illuminate\Support\Facades\DB::table('referrals')->where('sender_user_id', $request->user()->getAuthIdentifier())->exists();
                $request->session()->put('pwa.sent', ['v' => $sent, 'at' => now()->getTimestamp()]);

                return $sent;
            })()],
            'modules' => fn () => app(\App\Domains\Platform\Modules\Modules::class)->map(),
            // Whether this person sees Ally (Sprint 48b): by who they are (visitor, member or partner).
            'ally' => fn () => \App\Domains\Support\Ally\AllySettings::on($request->user() !== null ? 'member' : (is_string($request->session()->get('partner_portal')) ? 'partner' : 'visitor')),
            // "Powered by …" in the footer (super admin, Admin → System → General).
            'credit' => fn () => \App\Support\FooterCredit::shown(),
            // Header and footer menus set in Admin → Website (cached); the public layout falls back to its defaults.
            'menus' => fn () => $request->is('app', 'app/*', 'admin', 'admin/*') ? [] : \App\Domains\Content\Menus::forPublic(), // public pages only; only links that open
            // Real-time (Mercure): which team channel to listen on; null when broadcasting is off (tests, local).
            'realtime' => fn () => config('broadcasting.default') === 'mercure' && ($u = $request->user()) instanceof \App\Domains\Identity\Models\User
                ? ['team' => $teamOf()?->ulid] : null,
            // The member's role in their team: the menu shows billing, company, integrations and the API to leaders only.
            'teamRole' => fn () => ($u = $request->user()) instanceof \App\Domains\Identity\Models\User && ($t = $teamOf()) !== null
                ? ($t->leader_id === $u->id ? 'leader' : ($t->roleOf($u) ?? 'member')) : null,
            // Unread referral messages from the other team, for the Inbox and Sent badges.
            'referralUnread' => fn () => ($u = $request->user()) instanceof \App\Domains\Identity\Models\User && ($t = $teamOf()) !== null
                ? [...\App\Domains\Referrals\Conversations::unread($t), 'waiting' => \App\Domains\Referrals\InboxSources::waiting($t, $u)] : ['inbox' => 0, 'sent' => 0, 'waiting' => 0],
            // The bell: unread count and the five latest, on every signed-in page.
            'notifications' => fn () => ($u = $request->user()) instanceof \App\Domains\Identity\Models\User ? [
                'unread' => $u->unreadNotifications()->count(),
                'latest' => $u->notifications()->latest()->limit(5)->get()->map(fn ($n) => \App\Domains\Messaging\Http\Controllers\NotificationController::present($n)),
            ] : null,
            'app' => [
                'name' => config('seo.site_name'),
                'shortName' => config('seo.short_name'),
            ],
        ];
    }
}
