<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use App\Modules\Observability\Http\Controllers\ErrorLogController;
use App\Modules\Observability\Providers\ObservabilityServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/errors/* — client: every signed-in user, rate-limited per user; the log itself: superadmin only.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::post('client', [ErrorLogController::class, 'client'])
        ->middleware('throttle:'.ObservabilityServiceProvider::CLIENT_LIMITER)->name('errors.client');

    Route::middleware('can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS)->group(function (): void {
        Route::get('/', [ErrorLogController::class, 'index'])->name('errors.index');
        Route::get('{error}', [ErrorLogController::class, 'show'])->whereNumber('error')->name('errors.show');
        Route::patch('{error}', [ErrorLogController::class, 'update'])->whereNumber('error')->name('errors.update');
    });
});
