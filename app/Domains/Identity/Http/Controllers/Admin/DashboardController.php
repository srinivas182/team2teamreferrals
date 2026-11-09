<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Seo\SeoData;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/** Admin home. The full network dashboard (referrals, revenue, SLA trends) is built in Sprint 33. */
final class DashboardController
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'members' => User::role(Permissions::MEMBER)->count(),
                'staff' => User::role([Permissions::STAFF, Permissions::SUPER_ADMIN])->count(),
                'signInsToday' => Activity::query()->where('event', 'signed-in')->whereDate('created_at', today())->count(),
                'failedToday' => Activity::query()->where('event', 'sign-in-failed')->whereDate('created_at', today())->count(),
            ],
            'seo' => SeoData::private('Admin')->toArray(),
        ]);
    }
}
