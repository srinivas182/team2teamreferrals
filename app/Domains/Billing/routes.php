<?php

use App\Domains\Billing\Http\BillingController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/billing', [BillingController::class, 'show'])->name('billing');
    Route::post('app/billing/{type}/setup-intent', [BillingController::class, 'setupIntent'])->whereIn('type', ['team', 'company'])->middleware('throttle:20,10')->name('billing.setup-intent');
    Route::post('app/billing/{type}/card', [BillingController::class, 'saveCard'])->whereIn('type', ['team', 'company'])->middleware('throttle:20,10')->name('billing.card');
    Route::post('app/billing/{type}/invoices/{invoice}/pay', [BillingController::class, 'payNow'])->whereIn('type', ['team', 'company'])->whereNumber('invoice')->middleware('throttle:10,10')->name('billing.pay');
    Route::get('app/billing/{type}/invoices/{invoice}.pdf', [BillingController::class, 'invoicePdf'])->whereIn('type', ['team', 'company'])->whereNumber('invoice')->name('billing.invoice.pdf');
    Route::post('app/billing/{type}/seats/{seat}/cycle', [BillingController::class, 'cycle'])->whereIn('type', ['team', 'company'])->whereNumber('seat')->name('billing.cycle');
    Route::post('app/billing/{type}/coupon', [BillingController::class, 'redeem'])->whereIn('type', ['team', 'company'])->middleware('throttle:10,10')->name('billing.coupon');
});
