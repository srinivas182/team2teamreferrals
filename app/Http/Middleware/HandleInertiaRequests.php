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
            'flash' => ['status' => fn () => $request->session()->get('status')],
            'app' => [
                'name' => config('seo.site_name'),
                'shortName' => config('seo.short_name'),
            ],
        ];
    }
}
