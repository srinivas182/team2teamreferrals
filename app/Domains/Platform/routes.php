<?php

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Platform\Http\Controllers\DeliveryLogController;
use App\Domains\Platform\Http\Controllers\PrivateFileController;
use App\Domains\Platform\Http\Controllers\StripeWebhookController;
use App\Domains\Platform\Http\Controllers\SystemSettingsController;
use App\Domains\Platform\Http\Controllers\TwilioWebhookController;
use Illuminate\Support\Facades\Route;

// Provider webhooks: no session or CSRF; each verifies the provider's signature.
Route::post('webhooks/twilio/status', [TwilioWebhookController::class, 'status'])->name('webhooks.twilio.status');
Route::post('webhooks/twilio/inbound', [TwilioWebhookController::class, 'inbound'])->name('webhooks.twilio.inbound');
Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

// Private files: signed, expiring links for signed-in users only.
Route::middleware(['web', 'auth', 'signed'])->get('files/{path}', PrivateFileController::class)->where('path', '.*')->name('files.private');

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:settings.manage'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('system/export', [SystemSettingsController::class, 'export'])->name('system.export');
        Route::get('system/{tab?}', [SystemSettingsController::class, 'show'])->whereIn('tab', ['overview', 'general', 'email', 'sms', 'payments', 'storage', 'ai', 'modules'])->name('system');
        Route::put('system/general', [SystemSettingsController::class, 'updateGeneral'])->name('system.general');
        Route::put('system/email', [SystemSettingsController::class, 'updateEmail'])->name('system.email');
        Route::put('system/modules', [SystemSettingsController::class, 'updateModules'])->name('system.modules');
        Route::put('system/{channel}', [SystemSettingsController::class, 'updateChannel'])->whereIn('channel', ['sms', 'payments', 'storage', 'ai'])->name('system.channel');
        Route::post('system/email/test', [SystemSettingsController::class, 'testEmail'])->middleware('throttle:6,1')->name('system.email.test');
        Route::post('system/sms/test', [SystemSettingsController::class, 'testSms'])->middleware('throttle:6,1')->name('system.sms.test');
        Route::post('system/check/{provider}', [SystemSettingsController::class, 'check'])->middleware('throttle:12,1')->name('system.check');
        Route::post('system/check-all', [SystemSettingsController::class, 'checkAll'])->middleware('throttle:4,1')->name('system.check-all');
        Route::post('system/maintenance', [SystemSettingsController::class, 'maintenance'])->name('system.maintenance');
        Route::post('system/import', [SystemSettingsController::class, 'import'])->name('system.import');
        Route::get('deliveries', DeliveryLogController::class)->name('deliveries');
    });
