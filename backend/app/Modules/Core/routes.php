<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Core\Http\Controllers\HealthController;
use App\Modules\Core\Http\Controllers\NavBadgesController;
use App\Modules\Core\Http\Controllers\OpsJobsController;
use App\Modules\Core\Http\Controllers\OpsMigrateController;
use App\Modules\Core\Http\Middleware\RequireOpsSecret;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('core.health');

// Sidebar counters of the current user (NavBadgeProvider of each module).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->get('nav/badges', NavBadgesController::class)->name('core.nav.badges');

Route::middleware(RequireOpsSecret::class)->prefix('ops')->group(function (): void {
    Route::post('migrate', OpsMigrateController::class)->name('core.ops.migrate');
    Route::post('jobs/run', OpsJobsController::class)->name('core.ops.jobs');
});
