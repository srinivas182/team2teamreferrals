<?php

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Network\Industries\Http\AdminAgreementController;
use App\Domains\Network\Industries\Http\AdminIndustriesController;
use App\Domains\Network\Industries\Http\AdminIndustryMessagesController;
use App\Domains\Network\Industries\Http\AdminIndustryTemplatesController;
use App\Domains\Network\Industries\Http\AdminLaunchController;
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
        Route::get('launch', AdminLaunchController::class)->name('launch');
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
        Route::get('{industry}/messages', [AdminIndustryMessagesController::class, 'show'])->name('messages.show');
        Route::post('{industry}/messages', [AdminIndustryMessagesController::class, 'save'])->name('messages.save');
        Route::post('{industry}/messages/reset', [AdminIndustryMessagesController::class, 'reset'])->name('messages.reset');
        Route::post('{industry}/messages/{template}/activate', [AdminIndustryMessagesController::class, 'activate'])->name('messages.activate');
        Route::get('{industry}/agreement', [AdminAgreementController::class, 'show'])->name('agreement.show');
        Route::put('{industry}/agreement', [AdminAgreementController::class, 'save'])->name('agreement.save');
        Route::delete('{industry}/agreement', [AdminAgreementController::class, 'reset'])->name('agreement.reset');
        Route::post('{industry}/agreement/approve', [AdminAgreementController::class, 'approve'])->name('agreement.approve');
        Route::get('{industry}/agreement/preview.pdf', [AdminAgreementController::class, 'pdf'])->name('agreement.pdf');
        Route::get('{industry}/agreement/counsel-pack.pdf', [AdminAgreementController::class, 'counselPack'])->name('agreement.counsel-pack');
        Route::put('{industry}/agreement/addenda/{state}', [AdminAgreementController::class, 'saveAddendum'])->name('agreement.addendum');
        Route::get('{industry}/{kind}', [AdminIndustryTemplatesController::class, 'show'])->whereIn('kind', ['form', 'stages'])->name('templates.show');
        Route::put('{industry}/{kind}', [AdminIndustryTemplatesController::class, 'save'])->whereIn('kind', ['form', 'stages'])->name('templates.save');
        Route::delete('{industry}/{kind}', [AdminIndustryTemplatesController::class, 'reset'])->whereIn('kind', ['form', 'stages'])->name('templates.reset');
    });
