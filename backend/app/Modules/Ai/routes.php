<?php

declare(strict_types=1);

use App\Modules\Ai\Http\Controllers\AiController;
use App\Modules\Ai\Http\Controllers\AiPromptController;
use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/ai/* — superadmin only (same gate as the integrations admin). The test prompt is throttled: it costs money.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS])
    ->group(function (): void {
        Route::get('status', [AiController::class, 'status'])->name('ai.status');
        Route::post('test', [AiController::class, 'test'])->middleware('throttle:5,1')->name('ai.test');
        Route::get('stats', [AiController::class, 'stats'])->name('ai.stats');
        // Prompt editor: the instruction part is editable, OUTPUT/schema/parsing stay in code (docs/modules/ai.md).
        Route::prefix('prompts/{purpose}')->group(function (): void {
            Route::get('/', [AiPromptController::class, 'show'])->name('ai.prompts.show');
            Route::post('/', [AiPromptController::class, 'save'])->name('ai.prompts.save');
            Route::post('versions/{version}/activate', [AiPromptController::class, 'activate'])->whereNumber('version')->name('ai.prompts.activate');
            Route::post('builtin', [AiPromptController::class, 'builtin'])->name('ai.prompts.builtin');
            Route::put('capability', [AiPromptController::class, 'capability'])->name('ai.prompts.capability');
            Route::post('trial', [AiPromptController::class, 'trial'])->middleware('throttle:5,1')->name('ai.prompts.trial');
        });
    });
