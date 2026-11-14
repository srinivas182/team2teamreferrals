<?php

use App\Domains\Api\Http\Controllers\DevelopersController;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

// Public API docs, and the team's API tokens (Sprint 44a).
Route::middleware(['web'])->get('developers', [DevelopersController::class, 'docs'])->name('developers');
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', 'password.confirm'])->prefix('app/developers')->name('developers.')->group(function (): void {
    Route::get('/', [DevelopersController::class, 'index'])->name('index');
    Route::post('tokens', [DevelopersController::class, 'create'])->middleware('throttle:20,1')->name('tokens.create');
    Route::delete('tokens/{token}', [DevelopersController::class, 'revoke'])->name('tokens.revoke');
});
