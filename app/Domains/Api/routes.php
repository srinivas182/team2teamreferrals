<?php

use App\Domains\Api\Http\Controllers\AccountController;
use App\Domains\Api\Http\Controllers\ReferralsController;
use App\Domains\Api\Http\Controllers\SandboxController;
use App\Domains\Api\Http\Middleware\AuthenticateApiToken;
use App\Domains\Api\Http\Middleware\Idempotency;
use Illuminate\Support\Facades\Route;

// Public REST API v1 (Sprint 44a). Stateless: no sessions or cookies; token authentication, scopes, rate limits by
// plan, safe retries (Idempotency-Key) and problem-details errors. Documented at /developers.
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('openapi.json', fn () => response()->json(App\Domains\Api\ApiSpec::document()))->middleware('throttle:60,1')->name('openapi');

    Route::middleware([AuthenticateApiToken::class, 'throttle:api', Idempotency::class])->group(function (): void {
        Route::get('me', [AccountController::class, 'me'])->name('me');

        Route::middleware('api.scope:referrals:read')->group(function (): void {
            Route::get('referrals', [ReferralsController::class, 'index'])->name('referrals.index');
            Route::get('referrals/{id}', [ReferralsController::class, 'show'])->name('referrals.show');
            Route::get('referrals/{id}/events', [ReferralsController::class, 'events'])->name('referrals.events');
        });
        Route::middleware('api.scope:referrals:write')->group(function (): void {
            Route::post('referrals', [ReferralsController::class, 'store'])->name('referrals.store');
            Route::post('referrals/{id}/accept', [ReferralsController::class, 'accept'])->name('referrals.accept');
            Route::post('referrals/{id}/decline', [ReferralsController::class, 'decline'])->name('referrals.decline');
            Route::post('referrals/{id}/stage', [ReferralsController::class, 'stage'])->name('referrals.stage');
            Route::post('referrals/{id}/notes', [ReferralsController::class, 'note'])->name('referrals.notes');
            Route::post('sandbox/incoming-referrals', [SandboxController::class, 'incoming'])->name('sandbox.incoming');
            Route::post('sandbox/referrals/{id}/simulate', [SandboxController::class, 'simulate'])->name('sandbox.simulate');
        });
        Route::middleware('api.scope:leads:read')->group(function (): void {
            Route::get('leads', [AccountController::class, 'leads'])->name('leads.index');
            Route::get('leads/{id}', [AccountController::class, 'showLead'])->whereNumber('id')->name('leads.show');
        });
        Route::patch('leads/{id}', [AccountController::class, 'updateLead'])->whereNumber('id')->middleware('api.scope:leads:write')->name('leads.update');
        Route::middleware('api.scope:groups:read')->group(function (): void {
            Route::get('groups', [AccountController::class, 'groups'])->name('groups.index');
            Route::get('groups/{slug}', [AccountController::class, 'showGroup'])->name('groups.show');
            Route::get('groups/{slug}/route', [AccountController::class, 'route'])->name('groups.route');
            Route::get('cities', [AccountController::class, 'cities'])->name('cities');
        });
        Route::middleware('api.scope:seats:read')->group(function (): void {
            Route::get('seats', [AccountController::class, 'seats'])->name('seats.index');
            Route::get('seats/{id}', [AccountController::class, 'showSeat'])->whereNumber('id')->name('seats.show');
        });
        Route::get('profile', [AccountController::class, 'profile'])->middleware('api.scope:profile:read')->name('profile.show');
        Route::patch('profile', [AccountController::class, 'updateProfile'])->middleware('api.scope:profile:write')->name('profile.update');
    });
});
