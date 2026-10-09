<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\GoogleAuthController;
use App\Modules\Auth\Http\Controllers\LocalTestLoginController;
use Illuminate\Support\Facades\Route;

// /api/auth/google/* — browser redirects ('web' group: always a session, needed for the OAuth state check).
Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

// Never register a local test credential on a deployed PHP runtime.
if (app()->environment('local') && PHP_SAPI === 'cli-server') {
    Route::post('local-test-login', LocalTestLoginController::class)
        ->middleware('throttle:5,1')->name('auth.local-test-login');
}
