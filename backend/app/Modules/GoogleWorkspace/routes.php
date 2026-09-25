<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\GoogleWorkspace\Http\Controllers\GoogleStatusController;
use App\Modules\GoogleWorkspace\Http\Controllers\MeetingController;
use App\Modules\GoogleWorkspace\Http\Controllers\SheetsImportController;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/google/* — tokens never appear in any answer.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    // Any active user: can the card schedule meetings? Scheduling itself is checked by CandidatePolicy::update.
    Route::get('calendar', [GoogleStatusController::class, 'calendar'])->name('google.calendar');
    Route::post('candidates/{candidate}/meetings', [MeetingController::class, 'store'])
        ->whereNumber('candidate')->name('google.meetings.store');

    Route::middleware('can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS)->group(function (): void {
        Route::get('status', [GoogleStatusController::class, 'index'])->name('google.status');
        Route::post('sheets/inspect', [SheetsImportController::class, 'inspect'])->name('google.sheets.inspect');
        Route::get('sheets/imports', [SheetsImportController::class, 'index'])->name('google.sheets.imports.index');
        Route::post('sheets/imports', [SheetsImportController::class, 'store'])->name('google.sheets.imports.store');
        Route::patch('sheets/imports/{sheetImport}', [SheetsImportController::class, 'update'])
            ->whereNumber('sheetImport')->name('google.sheets.imports.update');
        Route::post('sheets/imports/{sheetImport}/run', [SheetsImportController::class, 'run'])
            ->whereNumber('sheetImport')->name('google.sheets.imports.run');
    });
});
