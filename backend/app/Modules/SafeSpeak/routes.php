<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\SafeSpeak\Http\Controllers\HandlerController;
use App\Modules\SafeSpeak\Providers\SafeSpeakServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/safe-speak/* — the handler side (session + auth). The anonymous side lives in routes.public.php (no session).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('me', [HandlerController::class, 'me'])->name('safe-speak.me');

    Route::middleware('can:'.SafeSpeakServiceProvider::HANDLE)->group(function (): void {
        Route::get('reports', [HandlerController::class, 'index'])->name('safe-speak.reports.index');
        Route::get('reports/{report}', [HandlerController::class, 'show'])->whereNumber('report')->name('safe-speak.reports.show');
        Route::patch('reports/{report}', [HandlerController::class, 'update'])->whereNumber('report')->name('safe-speak.reports.update');
        Route::post('reports/{report}/messages', [HandlerController::class, 'reply'])->whereNumber('report')->name('safe-speak.reports.reply');
    });
});
