<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Integrations\Http\Controllers\IntegrationsController;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/integrations/* — superadmin only. {integration} = registry key (unknown → 404, see provider binding).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS])
    ->group(function (): void {
        Route::get('/', [IntegrationsController::class, 'index'])->name('integrations.index');
        Route::put('ai-policy', [IntegrationsController::class, 'updateAiPolicy'])->name('integrations.ai-policy');
        Route::put('{integration}', [IntegrationsController::class, 'update'])->name('integrations.update');
        Route::get('{integration}/logs', [IntegrationsController::class, 'logs'])->name('integrations.logs');
        Route::post('{integration}/check', [IntegrationsController::class, 'check'])->name('integrations.check');
        Route::post('{integration}/status', [IntegrationsController::class, 'status'])->name('integrations.status');
    });
