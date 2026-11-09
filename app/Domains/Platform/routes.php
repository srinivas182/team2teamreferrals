<?php

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Platform\Http\Controllers\DeliveryLogController;
use App\Domains\Platform\Http\Controllers\SystemSettingsController;
use App\Domains\Platform\Http\Controllers\TwilioWebhookController;
use Illuminate\Support\Facades\Route;

// Provider webhooks: no session or CSRF; each verifies the provider's signature.
Route::post('webhooks/twilio/status', [TwilioWebhookController::class, 'status'])->name('webhooks.twilio.status');

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:settings.manage'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('system/{tab?}', [SystemSettingsController::class, 'show'])->whereIn('tab', ['general', 'email', 'sms'])->name('system');
        Route::put('system/general', [SystemSettingsController::class, 'updateGeneral'])->name('system.general');
        Route::put('system/email', [SystemSettingsController::class, 'updateEmail'])->name('system.email');
        Route::put('system/sms', [SystemSettingsController::class, 'updateSms'])->name('system.sms');
        Route::post('system/email/test', [SystemSettingsController::class, 'testEmail'])->middleware('throttle:6,1')->name('system.email.test');
        Route::post('system/sms/test', [SystemSettingsController::class, 'testSms'])->middleware('throttle:6,1')->name('system.sms.test');
        Route::get('deliveries', DeliveryLogController::class)->name('deliveries');
    });
