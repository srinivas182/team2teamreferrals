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
            'modules' => fn () => app(\App\Domains\Platform\Modules\Modules::class)->map(),
            // Real-time (Mercure): which team channel to listen on; null when broadcasting is off (tests, local).
            'realtime' => fn () => config('broadcasting.default') === 'mercure' && ($u = $request->user()) instanceof \App\Domains\Identity\Models\User
                ? ['team' => \App\Domains\Network\Seats\Models\Team::of($u)?->ulid] : null,
            // Unread referral messages from the other team, for the Inbox and Sent badges.
            'referralUnread' => fn () => ($u = $request->user()) instanceof \App\Domains\Identity\Models\User && ($t = \App\Domains\Network\Seats\Models\Team::of($u)) !== null
                ? \App\Domains\Referrals\Conversations::unread($t) : ['inbox' => 0, 'sent' => 0],
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
