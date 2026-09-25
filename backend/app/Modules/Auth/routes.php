<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\MeController;
use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

// /api/auth/* — JSON API, session cookie via Sanctum (statefulApi).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('auth.me');
    Route::patch('me/locale', [MeController::class, 'updateLocale'])->name('auth.me.locale');
    Route::post('logout', [MeController::class, 'logout'])->name('auth.logout');
});
