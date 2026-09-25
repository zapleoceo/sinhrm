<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Scripts\Http\Controllers\CandidateScriptController;
use App\Modules\Scripts\Http\Controllers\ScriptController;
use App\Modules\Scripts\Http\Controllers\ScriptReportController;
use App\Modules\Scripts\Http\Controllers\TaskController;
use App\Modules\Scripts\Providers\ScriptsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/* of the Scripts module. Reading: any active user; script changes: gate scripts-manage (superadmin, admin).
// No DELETE: scripts are archived, versions are immutable.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('scripts', [ScriptController::class, 'index'])->name('scripts.index');
    Route::get('scripts/{script}', [ScriptController::class, 'show'])->whereNumber('script')->name('scripts.show');
    Route::get('scripts/{script}/versions', [ScriptController::class, 'versions'])->whereNumber('script')->name('scripts.versions');
    Route::post('scripts/{script}/test', [ScriptController::class, 'test'])->whereNumber('script')->name('scripts.test');

    Route::middleware('can:'.ScriptsServiceProvider::MANAGE)->group(function (): void {
        Route::post('scripts', [ScriptController::class, 'store'])->name('scripts.store');
        Route::patch('scripts/{script}', [ScriptController::class, 'update'])->whereNumber('script')->name('scripts.update');
        Route::put('scripts/{script}/draft', [ScriptController::class, 'saveDraft'])->whereNumber('script')->name('scripts.draft');
        Route::post('scripts/{script}/publish', [ScriptController::class, 'publish'])->whereNumber('script')->name('scripts.publish');
        Route::post('scripts/{script}/activate/{version}', [ScriptController::class, 'activate'])
            ->whereNumber('script')->whereNumber('version')->name('scripts.activate');
    });

    Route::get('candidates/{candidate}/templates', [CandidateScriptController::class, 'templates'])
        ->whereNumber('candidate')->name('scripts.candidate-templates');
    Route::get('touchpoints/{touchpoint}/evaluation', [CandidateScriptController::class, 'evaluation'])
        ->whereNumber('touchpoint')->name('scripts.touchpoint-evaluation');
    Route::get('reports/scripts', ScriptReportController::class)->name('scripts.report');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
});
