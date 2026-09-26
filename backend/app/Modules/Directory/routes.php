<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Http\Controllers\DirectoryController;
use App\Modules\Directory\Providers\DirectoryServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/directory/* — {dictionary} = branches|cities|departments|positions. No DELETE: items are disabled.
$dictionaries = array_map(static fn (DictionaryType $t): string => $t->value, DictionaryType::cases());

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () use ($dictionaries): void {
    // Reading: any active user (dictionaries feed filters and forms everywhere).
    Route::get('{dictionary}', [DirectoryController::class, 'index'])
        ->whereIn('dictionary', $dictionaries)
        ->name('directory.index');

    Route::middleware('can:'.DirectoryServiceProvider::MANAGE_DIRECTORY)->group(function () use ($dictionaries): void {
        Route::post('{dictionary}', [DirectoryController::class, 'store'])
            ->whereIn('dictionary', $dictionaries)
            ->name('directory.store');
        Route::patch('{dictionary}/{id}', [DirectoryController::class, 'update'])
            ->whereIn('dictionary', $dictionaries)
            ->whereNumber('id')
            ->name('directory.update');
    });
});
