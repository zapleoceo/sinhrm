<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\HealthController;
use App\Modules\Core\Http\Controllers\OpsMigrateController;
use App\Modules\Core\Http\Middleware\RequireOpsSecret;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('core.health');

Route::middleware(RequireOpsSecret::class)->prefix('ops')->group(function (): void {
    Route::post('migrate', OpsMigrateController::class)->name('core.ops.migrate');
});
