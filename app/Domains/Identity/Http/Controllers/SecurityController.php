<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Devices\DeviceRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityController
{
    public function __construct(private readonly DeviceRecorder $devices) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $current = $this->devices->find($user, $request);
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $status = session('status');

        return Inertia::render('app/settings/Security', [
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending' => $pending,
                'qrSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
                'setupKey' => $pending ? decrypt((string) $user->two_factor_secret) : null,
                'recoveryCodes' => $user->hasTwoFactorEnabled() && in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true)
                    ? $user->recoveryCodes() : null,
            ],
            'passkeys' => $user->passkeys()->latest()->get(['id', 'name', 'last_used_at', 'created_at'])->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'lastUsed' => $p->last_used_at?->diffForHumans(),
                'added' => $p->created_at?->toFormattedDateString(),
            ]),
            'devices' => $user->devices()->orderByDesc('last_seen_at')->get()->map(fn (UserDevice $d) => [
                'id' => $d->id,
                'label' => $d->label,
                'ip' => $d->ip_address,
                'lastSeen' => $d->last_seen_at->diffForHumans(),
                'current' => $current?->is($d) ?? false,
                'trusted' => $d->isTrustedForTwoFactor(),
            ]),
            'status' => $status,
            'seo' => SeoData::private('Security')->toArray(),
        ]);
    }

    public function destroyDevice(Request $request, UserDevice $device): RedirectResponse
    {
        abort_unless($device->user_id === $request->user()?->getKey(), 404);
        $device->delete();

        return back()->with('status', 'device-removed');
    }

    public function logoutOtherDevices(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password:web']], ['password.current_password' => 'That isn’t your current password.']);
        Auth::logoutOtherDevices((string) $request->input('password'));
        $user = $this->user($request);
        $current = $this->devices->find($user, $request);
        $user->devices()->when($current, fn ($q) => $q->whereKeyNot($current?->getKey()))->delete();

        return back()->with('status', 'other-devices-signed-out');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
