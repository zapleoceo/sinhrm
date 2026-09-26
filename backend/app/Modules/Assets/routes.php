<?php

declare(strict_types=1);

use App\Modules\Assets\Http\Controllers\AssetController;
use App\Modules\Assets\Providers\AssetsServiceProvider;
use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

// /api/assets/* — inventory: gate assets-manage (HR admins); an employee's assets: People job tier (else 404).
// No DELETE: assets are written off, never removed (the history must stay).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('employee/{employee}', [AssetController::class, 'ofEmployee'])->whereNumber('employee')->name('assets.employee');

    Route::middleware('can:'.AssetsServiceProvider::MANAGE)->group(function (): void {
        Route::get('types', [AssetController::class, 'types'])->name('assets.types.index');
        Route::post('types', [AssetController::class, 'storeType'])->name('assets.types.store');
        Route::patch('types/{type}', [AssetController::class, 'updateType'])->whereNumber('type')->name('assets.types.update');
        Route::get('/', [AssetController::class, 'index'])->name('assets.index');
        Route::post('/', [AssetController::class, 'store'])->name('assets.store');
        Route::get('{asset}', [AssetController::class, 'show'])->whereNumber('asset')->name('assets.show');
        Route::patch('{asset}', [AssetController::class, 'update'])->whereNumber('asset')->name('assets.update');
        Route::post('{asset}/assign', [AssetController::class, 'assign'])->whereNumber('asset')->name('assets.assign');
        Route::post('{asset}/return', [AssetController::class, 'return'])->whereNumber('asset')->name('assets.return');
    });
});
