<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

// /api/auth/google/* — browser redirects ('web' group: always a session, needed for the OAuth state check).
Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
