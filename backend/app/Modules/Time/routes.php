<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Time\Http\Controllers\TimeController;
use App\Modules\Time\Providers\TimeServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/time/* — the week of the employee (own; managers — subtree; admins — all). Invisible → 404, not allowed → 403.
// Schedules: gate time-manage (admins).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('week', [TimeController::class, 'week'])->name('time.week');
    Route::put('week', [TimeController::class, 'save'])->name('time.week.save');
    Route::post('week/submit', [TimeController::class, 'submit'])->name('time.week.submit');
    Route::post('timesheets/{timesheet}/decision', [TimeController::class, 'decide'])->whereNumber('timesheet')->name('time.decide');
    Route::get('approvals', [TimeController::class, 'approvals'])->name('time.approvals');
    Route::get('team', [TimeController::class, 'team'])->name('time.team');
    Route::get('schedules', [TimeController::class, 'schedules'])->name('time.schedules');

    Route::middleware('can:'.TimeServiceProvider::MANAGE)->group(function (): void {
        Route::put('schedules', [TimeController::class, 'saveSchedule'])->name('time.schedules.save');
        Route::delete('schedules/{branch}', [TimeController::class, 'deleteSchedule'])->whereNumber('branch')->name('time.schedules.delete');
    });
});
