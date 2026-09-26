<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\HiringRequests\Http\Controllers\HiringRequestController;
use App\Modules\HiringRequests\Http\Controllers\HiringSettingsController;
use App\Modules\HiringRequests\Providers\HiringRequestsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/hiring-requests/* — every active user; the service decides (create: admins, managers, listed creators;
// see: requester, recruiter, route participants, admins; invisible → 404). Settings: gate hiring-manage (admins).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('/', [HiringRequestController::class, 'index'])->name('hiring.index');
    Route::get('inbox', [HiringRequestController::class, 'inbox'])->name('hiring.inbox');
    Route::get('meta', [HiringRequestController::class, 'meta'])->name('hiring.meta');
    Route::post('/', [HiringRequestController::class, 'store'])->name('hiring.store');
    Route::get('{id}', [HiringRequestController::class, 'show'])->whereNumber('id')->name('hiring.show');
    Route::patch('{id}', [HiringRequestController::class, 'update'])->whereNumber('id')->name('hiring.update');
    Route::post('{id}/submit', [HiringRequestController::class, 'submit'])->whereNumber('id')->name('hiring.submit');
    Route::post('{id}/decision', [HiringRequestController::class, 'decide'])->whereNumber('id')->name('hiring.decide');
    Route::post('{id}/cancel', [HiringRequestController::class, 'cancel'])->whereNumber('id')->name('hiring.cancel');

    Route::middleware('can:'.HiringRequestsServiceProvider::MANAGE)->group(function (): void {
        Route::post('{id}/close', [HiringRequestController::class, 'close'])->whereNumber('id')->name('hiring.close');
        Route::post('{id}/vacancy', [HiringRequestController::class, 'createVacancy'])->whereNumber('id')->name('hiring.vacancy');
        Route::post('{id}/link-vacancy', [HiringRequestController::class, 'linkVacancy'])->whereNumber('id')->name('hiring.link-vacancy');
        Route::get('settings', [HiringSettingsController::class, 'show'])->name('hiring.settings.show');
        Route::put('settings', [HiringSettingsController::class, 'update'])->name('hiring.settings.update');
    });
});
