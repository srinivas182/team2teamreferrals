<?php

use App\Domains\Identity\Http\Controllers\Admin\AuditLogController;
use App\Domains\Identity\Http\Controllers\Admin\DashboardController;
use App\Domains\Identity\Http\Controllers\Admin\StaffController;
use App\Domains\Identity\Http\Controllers\MagicLinkController;
use App\Domains\Identity\Http\Controllers\PhoneVerificationController;
use App\Domains\Identity\Http\Controllers\PortalHomeController;
use App\Domains\Identity\Http\Controllers\SecureAccountController;
use App\Domains\Identity\Http\Controllers\SecurityController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsurePhoneIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'noindex:always'])->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::post('login/magic', [MagicLinkController::class, 'store'])->name('login.magic');
        Route::get('login/magic/{token}', [MagicLinkController::class, 'consume'])->middleware('signed')->name('login.magic.consume');
    });

    // "This wasn't me" from new-device emails: works signed in or not.
    Route::get('account/{user}/secure', [SecureAccountController::class, 'show'])->middleware('signed')->name('account.secure');
    Route::post('account/{user}/secure', [SecureAccountController::class, 'store'])->middleware('signed')->name('account.secure.store');

    Route::middleware(['auth', EnsureAccountIsActive::class])->group(function (): void {
        Route::get('verify-phone', [PhoneVerificationController::class, 'show'])->name('verification.phone');
        Route::post('verify-phone/send', [PhoneVerificationController::class, 'send'])->name('verification.phone.send');
        Route::post('verify-phone/email', [PhoneVerificationController::class, 'sendByEmail'])->name('verification.phone.email');
        Route::post('verify-phone', [PhoneVerificationController::class, 'verify'])->name('verification.phone.verify');
        Route::put('verify-phone/number', [PhoneVerificationController::class, 'update'])->name('verification.phone.update');

        // Security settings need only a verified email, so staff without a mobile can still turn on two-step sign-in.
        Route::prefix('app/settings/security')->middleware('verified')->group(function (): void {
            Route::get('/', [SecurityController::class, 'show'])->middleware('password.confirm')->name('settings.security');
            Route::delete('devices/{device}', [SecurityController::class, 'destroyDevice'])->name('settings.devices.destroy');
            Route::post('other-devices', [SecurityController::class, 'logoutOtherDevices'])->name('settings.devices.logout-others');
        });

        // The member portal: signed in, active, email and mobile verified.
        Route::prefix('app')->middleware(['verified', EnsurePhoneIsVerified::class])->group(function (): void {
            Route::get('/', PortalHomeController::class)->name('app.home');
        });

        // Admin: staff and super admins with two-step sign-in, each screen behind its permission.
        Route::prefix('admin')->name('admin.')->middleware(['verified', EnsureStaff::class])->group(function (): void {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::middleware('can:staff.manage')->group(function (): void {
                Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
                Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
                Route::put('staff/{user}', [StaffController::class, 'update'])->name('staff.update');
                Route::patch('staff/{user}/active', [StaffController::class, 'toggle'])->name('staff.toggle');
            });
            Route::middleware('can:audit.view')->group(function (): void {
                Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
                Route::get('audit/export', [AuditLogController::class, 'export'])->name('audit.export');
            });
        });
    });
});
