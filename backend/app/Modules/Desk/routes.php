<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Desk\Http\Controllers\DeskCaseController;
use App\Modules\Desk\Http\Controllers\DeskCategoryController;
use App\Modules\Desk\Providers\DeskServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/desk/* — employees open and follow their own cases; the queue and categories: gate desk-manage (HR admins).
// Not visible → 404. No DELETE: cases are closed, categories deactivated.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('categories', [DeskCategoryController::class, 'index'])->name('desk.categories.index');
    Route::get('cases/mine', [DeskCaseController::class, 'mine'])->name('desk.cases.mine');
    Route::post('cases', [DeskCaseController::class, 'store'])->name('desk.cases.store');
    Route::get('cases/{case}', [DeskCaseController::class, 'show'])->whereNumber('case')->name('desk.cases.show');
    Route::patch('cases/{case}', [DeskCaseController::class, 'update'])->whereNumber('case')->name('desk.cases.update');
    Route::post('cases/{case}/comments', [DeskCaseController::class, 'comment'])->whereNumber('case')->name('desk.cases.comment');
    Route::post('cases/{case}/attachments', [DeskCaseController::class, 'attach'])->whereNumber('case')->name('desk.cases.attach');
    Route::get('cases/{case}/attachments/{attachment}', [DeskCaseController::class, 'download'])
        ->whereNumber(['case', 'attachment'])->name('desk.cases.download');

    Route::middleware('can:'.DeskServiceProvider::MANAGE)->group(function (): void {
        Route::get('cases', [DeskCaseController::class, 'queue'])->name('desk.cases.queue');
        Route::post('categories', [DeskCategoryController::class, 'store'])->name('desk.categories.store');
        Route::patch('categories/{category}', [DeskCategoryController::class, 'update'])->whereNumber('category')->name('desk.categories.update');
    });
});
