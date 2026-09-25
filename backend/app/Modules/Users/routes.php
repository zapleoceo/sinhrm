<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Users\Http\Controllers\UsersController;
use App\Modules\Users\Providers\UsersServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/users/* — superadmin only. No DELETE: users are blocked, never removed.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.UsersServiceProvider::MANAGE_USERS])
    ->group(function (): void {
        Route::get('/', [UsersController::class, 'index'])->name('users.index');
        Route::post('/', [UsersController::class, 'store'])->name('users.store');
        Route::patch('{user}', [UsersController::class, 'update'])->whereNumber('user')->name('users.update');
    });
