<?php

declare(strict_types=1);

use App\Modules\Ai\Http\Controllers\AiController;
use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/ai/* — superadmin only (same gate as the integrations admin). The test prompt is throttled: it costs money.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS])
    ->group(function (): void {
        Route::get('status', [AiController::class, 'status'])->name('ai.status');
        Route::post('test', [AiController::class, 'test'])->middleware('throttle:5,1')->name('ai.test');
    });
