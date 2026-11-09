<?php

use App\Domains\Identity\Http\Controllers\MagicLinkController;
use App\Domains\Identity\Http\Controllers\PhoneVerificationController;
use App\Domains\Identity\Http\Controllers\PortalHomeController;
use App\Domains\Identity\Http\Controllers\SecurityController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsurePhoneIsVerified;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'noindex:always'])->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::post('login/magic', [MagicLinkController::class, 'store'])->name('login.magic');
        Route::get('login/magic/{token}', [MagicLinkController::class, 'consume'])->middleware('signed')->name('login.magic.consume');
    });

    Route::middleware(['auth', EnsureAccountIsActive::class])->group(function (): void {
        Route::get('verify-phone', [PhoneVerificationController::class, 'show'])->name('verification.phone');
        Route::post('verify-phone/send', [PhoneVerificationController::class, 'send'])->name('verification.phone.send');
        Route::post('verify-phone', [PhoneVerificationController::class, 'verify'])->name('verification.phone.verify');
        Route::put('verify-phone/number', [PhoneVerificationController::class, 'update'])->name('verification.phone.update');

        // The member portal: signed in, active, email and mobile verified.
        Route::prefix('app')->middleware(['verified', EnsurePhoneIsVerified::class])->group(function (): void {
            Route::get('/', PortalHomeController::class)->name('app.home');
            Route::get('settings/security', [SecurityController::class, 'show'])->middleware('password.confirm')->name('settings.security');
            Route::delete('settings/security/devices/{device}', [SecurityController::class, 'destroyDevice'])->name('settings.devices.destroy');
            Route::post('settings/security/other-devices', [SecurityController::class, 'logoutOtherDevices'])->name('settings.devices.logout-others');
        });
    });
});
