<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Reports\Http\Controllers\ReportsController;
use Illuminate\Support\Facades\Route;

// /api/reports/* of the Reports module (the older /api/reports/{touches,funnel,sources,reject-reasons,scripts} stay in
// Recruiting/Scripts). Every active user; each report/dataset decides availability, data follow the user's scope.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('catalog', [ReportsController::class, 'index'])->name('reports.catalog');
    Route::get('catalog/{key}', [ReportsController::class, 'run'])->where('key', '[a-z_]+')->name('reports.run');
    Route::get('catalog/{key}/csv', [ReportsController::class, 'csv'])->where('key', '[a-z_]+')->name('reports.csv');

    Route::get('builder/datasets', [ReportsController::class, 'datasets'])->name('reports.builder.datasets');
    Route::post('builder/run', [ReportsController::class, 'build'])->name('reports.builder.run');
    Route::post('builder/csv', [ReportsController::class, 'buildCsv'])->name('reports.builder.csv');

    Route::get('saved', [ReportsController::class, 'saved'])->name('reports.saved.index');
    Route::post('saved', [ReportsController::class, 'store'])->name('reports.saved.store');
    Route::put('saved/{id}', [ReportsController::class, 'update'])->whereNumber('id')->name('reports.saved.update');
    Route::delete('saved/{id}', [ReportsController::class, 'destroy'])->whereNumber('id')->name('reports.saved.destroy');
    Route::get('saved/{id}/run', [ReportsController::class, 'runSaved'])->whereNumber('id')->name('reports.saved.run');
});
