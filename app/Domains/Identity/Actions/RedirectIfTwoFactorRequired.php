<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Devices\DeviceRecorder;
use App\Domains\Identity\Models\User;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/** Fortify's 2FA step, skipped on devices the user chose to trust for 30 days. */
final class RedirectIfTwoFactorRequired extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && $user->hasTwoFactorEnabled()) {
            $device = app(DeviceRecorder::class)->find($user, $request);
            if ($device === null || ! $device->isTrustedForTwoFactor()) {
                return $this->twoFactorChallengeResponse($request, $user);
            }
        }

        return $next($request);
    }
}
