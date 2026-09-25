<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Overview\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Home page data; every active role, figures limited to the user's branches (Recruiting scope).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('overview.dashboard');
});
