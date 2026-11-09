<?php

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Network\Industries\Http\AdminIndustriesController;
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

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:industries.manage'])
    ->prefix('admin/industries')->name('admin.industries.')->group(function (): void {
        Route::get('/', [AdminIndustriesController::class, 'index'])->name('index');
        Route::post('/', [AdminIndustriesController::class, 'store'])->name('store');
        Route::get('licensing.csv', [AdminIndustriesController::class, 'exportAllLicensing'])->name('licensing.export-all');
        Route::post('licensing/import', [AdminIndustriesController::class, 'importLicensing'])->name('licensing.import');
        Route::post('fees/unlock', [AdminIndustriesController::class, 'unlockFees'])->name('fees.unlock');
        Route::post('fees/lock', [AdminIndustriesController::class, 'lockFees'])->name('fees.lock');
        Route::get('{industry}', [AdminIndustriesController::class, 'show'])->name('show');
        Route::put('{industry}', [AdminIndustriesController::class, 'update'])->name('update');
        Route::put('{industry}/licensing/{state}', [AdminIndustriesController::class, 'saveLicensing'])->name('licensing.save');
        Route::get('{industry}/licensing.csv', [AdminIndustriesController::class, 'exportLicensing'])->name('licensing.export');
        Route::post('{industry}/approve', [AdminIndustriesController::class, 'approve'])->name('approve');
        Route::post('{industry}/revoke', [AdminIndustriesController::class, 'revoke'])->name('revoke');
        Route::post('{industry}/status', [AdminIndustriesController::class, 'status'])->name('status');
        Route::put('{industry}/fee', [AdminIndustriesController::class, 'saveFee'])->name('fee');
    });
