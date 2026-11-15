<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Content\Events\ContactMessageReceived;
use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Prelaunch\Events\ReservationConfirmed;
use App\Domains\Network\Seats\Events\SeatEventRecorded;
use Illuminate\Auth\Events\Registered;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/** The growth engine (Sprint 46): prospects, where they come from, and prospect email. */
final class GrowthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Registered::class, fn (Registered $e) => $e->user instanceof User ? Prospects::onSignup($e->user) : null);
        Event::listen(SeatEventRecorded::class, fn (SeatEventRecorded $e) => Prospects::onSeatEvent($e->seat, $e->team, $e->event));
        Event::listen(ReservationConfirmed::class, fn (ReservationConfirmed $e) => Prospects::onReservation($e->reservationId));
        Event::listen(ContactMessageReceived::class, fn (ContactMessageReceived $e) => Prospects::onContactMessage($e->messageId));

        $this->routes();

        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->call(fn () => Prospects::remindTasks())->dailyAt('13:10')->name('growth:task-reminders')->onOneServer();
            // Import files are only needed between upload and confirm.
            $s->call(function (): void {
                foreach (Storage::disk('local')->files('prospect-imports') as $f) {
                    if (Storage::disk('local')->lastModified($f) < now()->subDay()->getTimestamp()) {
                        Storage::disk('local')->delete($f);
                    }
                }
            })->dailyAt('04:50')->name('growth:import-cleanup')->onOneServer();
        });
    }

    private function routes(): void
    {
        Route::middleware(['web', 'noindex:always', 'signed'])->group(function (): void {
            Route::get('growth/unsubscribe/{prospect}', [Http\GrowthController::class, 'unsubscribePage'])->name('growth.unsubscribe');
            Route::post('growth/unsubscribe/{prospect}', [Http\GrowthController::class, 'unsubscribe'])->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:30,1')->name('growth.unsubscribe.post');
        });

        $prospectRoutes = function (): void {
            Route::get('prospects', [Http\ProspectsController::class, 'index'])->name('prospects');
            Route::post('prospects', [Http\ProspectsController::class, 'store'])->name('prospects.store');
            Route::post('prospects/bulk', [Http\ProspectsController::class, 'bulk'])->name('prospects.bulk');
            Route::post('prospects/import', [Http\ProspectsController::class, 'importPreview'])->middleware('throttle:10,1')->name('prospects.import');
            Route::post('prospects/import/confirm', [Http\ProspectsController::class, 'importConfirm'])->middleware('throttle:10,1')->name('prospects.import.confirm');
            Route::post('prospects/views', [Http\ProspectsController::class, 'saveView'])->name('prospects.views');
            Route::delete('prospects/views/{view}', [Http\ProspectsController::class, 'deleteView'])->whereNumber('view')->name('prospects.views.delete');
            Route::post('prospects/tasks/{task}', [Http\ProspectsController::class, 'toggleTask'])->whereNumber('task')->name('prospects.tasks.toggle');
            Route::get('prospects/{prospect}', [Http\ProspectsController::class, 'show'])->where('prospect', '[0-9a-z]{26}')->name('prospects.show');
            Route::patch('prospects/{prospect}', [Http\ProspectsController::class, 'update'])->where('prospect', '[0-9a-z]{26}')->name('prospects.update');
            Route::post('prospects/{prospect}/stage', [Http\ProspectsController::class, 'stage'])->where('prospect', '[0-9a-z]{26}')->name('prospects.stage');
            Route::post('prospects/{prospect}/owner', [Http\ProspectsController::class, 'assign'])->where('prospect', '[0-9a-z]{26}')->name('prospects.owner');
            Route::post('prospects/{prospect}/notes', [Http\ProspectsController::class, 'note'])->where('prospect', '[0-9a-z]{26}')->name('prospects.notes');
            Route::post('prospects/{prospect}/tasks', [Http\ProspectsController::class, 'task'])->where('prospect', '[0-9a-z]{26}')->name('prospects.tasks');
            Route::delete('prospects/{prospect}', [Http\ProspectsController::class, 'forget'])->where('prospect', '[0-9a-z]{26}')->name('prospects.forget');
        };
        // Staff: everyone.
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:growth.manage'])->prefix('admin/growth')->name('admin.growth.')->group(function () use ($prospectRoutes): void {
            Route::get('/', fn () => redirect('/admin/growth/prospects'))->name('home');
            Route::get('settings', [Http\GrowthController::class, 'settings'])->name('settings');
            Route::post('settings', [Http\GrowthController::class, 'saveSettings'])->name('settings.save');
            $prospectRoutes();
        });
        // Network owners: their networks' prospects (checked in Prospects::visibleTo).
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->prefix('app/group/{group}')->name('group.')->group($prospectRoutes);
        Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->get('app/prospects/{prospect}', [Http\ProspectsController::class, 'go'])->name('prospects.go');
    }
}
