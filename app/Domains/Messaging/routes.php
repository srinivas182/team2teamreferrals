<?php

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Messaging\Http\Controllers\Admin\TemplatesController;
use App\Domains\Messaging\Http\Controllers\NotificationController;
use App\Domains\Messaging\Http\Controllers\PreferencesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('app/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('app/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('app/settings/notifications', [PreferencesController::class, 'show'])->name('settings.notifications');
    Route::put('app/settings/notifications', [PreferencesController::class, 'update'])->name('settings.notifications.update');
    Route::post('app/push-subscriptions', [PreferencesController::class, 'subscribe'])->middleware(['throttle:10,1', 'module:push'])->name('push.subscribe');
    Route::delete('app/push-subscriptions', [PreferencesController::class, 'unsubscribe'])->name('push.unsubscribe');

    Route::prefix('admin/templates')->name('admin.templates.')->middleware([EnsureStaff::class, 'can:content.manage'])->group(function (): void {
        Route::get('/', [TemplatesController::class, 'index'])->name('index');
        Route::get('{type}', [TemplatesController::class, 'edit'])->name('edit');
        Route::post('{type}', [TemplatesController::class, 'store'])->name('store');
        Route::post('versions/{template}/activate', [TemplatesController::class, 'activate'])->name('activate');
        Route::post('{type}/{channel}/reset', [TemplatesController::class, 'reset'])->name('reset');
    });
});
