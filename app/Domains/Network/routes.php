<?php

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Network\Locations\Http\AdminLocationsController;
use App\Domains\Network\Locations\Http\LocationLookupController;
use Illuminate\Support\Facades\Route;

Route::get('locations/cities', [LocationLookupController::class, 'cities'])->middleware('throttle:120,1')->name('locations.cities');

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:locations.manage'])
    ->prefix('admin/locations')->name('admin.locations.')->group(function (): void {
        Route::get('/', [AdminLocationsController::class, 'index'])->name('index');
        Route::patch('states/{state}', [AdminLocationsController::class, 'toggleState'])->name('states.toggle');
        Route::patch('cities/{city}', [AdminLocationsController::class, 'updateCity'])->name('cities.update');
        Route::post('cities/{city}/aliases', [AdminLocationsController::class, 'addAlias'])->name('cities.aliases.store');
        Route::delete('aliases/{alias}', [AdminLocationsController::class, 'removeAlias'])->name('aliases.destroy');
        Route::post('cities/{city}/merge', [AdminLocationsController::class, 'merge'])->name('cities.merge');
    });
