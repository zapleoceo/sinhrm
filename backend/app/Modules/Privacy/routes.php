<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Privacy\Http\Controllers\PersonalDataController;
use App\Modules\Privacy\Providers\PrivacyServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/privacy/* — personal-data rights (Law No. 2297-VI): superadmin and admin only (gate privacy-manage).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.PrivacyServiceProvider::MANAGE])->group(function (): void {
    Route::get('settings', [PersonalDataController::class, 'settings'])->name('privacy.settings.show');
    Route::put('settings', [PersonalDataController::class, 'updateSettings'])->name('privacy.settings.update');
    Route::get('{type}/{id}/export', [PersonalDataController::class, 'export'])
        ->whereIn('type', ['candidate', 'employee'])->whereNumber('id')->name('privacy.export');
    Route::post('{type}/{id}/erase', [PersonalDataController::class, 'erase'])
        ->whereIn('type', ['candidate', 'employee'])->whereNumber('id')->name('privacy.erase');
    Route::get('{type}/{id}/requests', [PersonalDataController::class, 'requests'])
        ->whereIn('type', ['candidate', 'employee'])->whereNumber('id')->name('privacy.requests');
});
