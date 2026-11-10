<?php

use App\Domains\Billing\Http\BillingController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/billing', [BillingController::class, 'show'])->name('billing');
    Route::post('app/billing/{type}/setup-intent', [BillingController::class, 'setupIntent'])->whereIn('type', ['team', 'company'])->middleware('throttle:20,10')->name('billing.setup-intent');
    Route::post('app/billing/{type}/card', [BillingController::class, 'saveCard'])->whereIn('type', ['team', 'company'])->middleware('throttle:20,10')->name('billing.card');
});
