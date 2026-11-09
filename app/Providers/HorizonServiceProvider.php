<?php

namespace App\Providers;

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/** Queue dashboard at /horizon: super admins with two-step sign-in only. Long waits email the support address. */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        $support = app(Settings::class)->get('general.support_email');
        if ($support) {
            Horizon::routeMailNotificationsTo($support);
        }
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => $user instanceof User && $user->hasRole('super_admin') && $user->hasTwoFactorEnabled());
    }
}
