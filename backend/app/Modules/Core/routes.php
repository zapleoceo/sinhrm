<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Core\Http\Controllers\HealthController;
use App\Modules\Core\Http\Controllers\ModuleSettingsController;
use App\Modules\Core\Http\Controllers\NavBadgesController;
use App\Modules\Core\Http\Controllers\OpsJobsController;
use App\Modules\Core\Http\Controllers\OpsMigrateController;
use App\Modules\Core\Http\Middleware\RequireOpsSecret;
use App\Modules\Core\Providers\CoreServiceProvider;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('core.health');

// Sidebar counters of the current user (NavBadgeProvider of each module).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->get('nav/badges', NavBadgesController::class)->name('core.nav.badges');

Route::middleware(RequireOpsSecret::class)->prefix('ops')->group(function (): void {
    Route::post('migrate', OpsMigrateController::class)->name('core.ops.migrate');
    Route::post('jobs/run', OpsJobsController::class)->name('core.ops.jobs');
});

// "Модулі" admin page: company-wide on/off + roles per module (docs/modules/modules-access.md).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.CoreServiceProvider::MANAGE_MODULES])
    ->prefix('modules')
    ->group(function (): void {
        Route::get('/', [ModuleSettingsController::class, 'index'])->name('core.modules.index');
        Route::put('{key}', [ModuleSettingsController::class, 'update'])->where('key', '[a-z0-9-]+')->name('core.modules.update');
    });
