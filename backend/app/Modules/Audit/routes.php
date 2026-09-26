<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\AuditController;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

// /api/audit/* — superadmin only. Entity "History" tabs live in their own modules (people, candidates).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.AuditServiceProvider::VIEW_AUDIT])
    ->group(function (): void {
        Route::get('/', [AuditController::class, 'index'])->name('audit.index');
        Route::get('options', [AuditController::class, 'options'])->name('audit.options');
    });
