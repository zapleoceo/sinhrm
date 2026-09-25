<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\GoogleWorkspace\Http\Controllers\GoogleConnectController;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/google/connect* — browser redirects of the OAuth consent ('web' group: the session keeps the state).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS])
    ->group(function (): void {
        Route::get('connect', [GoogleConnectController::class, 'redirect'])->name('google.connect');
        Route::get('connect/callback', [GoogleConnectController::class, 'callback'])->name('google.connect.callback');
    });
