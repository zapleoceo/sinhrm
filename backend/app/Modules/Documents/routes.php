<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\DocumentTemplateController;
use App\Modules\Documents\Providers\DocumentsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/* of the Documents module. Reading: PeopleScope (admin / the employee / managers above); writing and
// templates: gate documents-manage (superadmin, admin — they act as HR). No DELETE: documents are archived.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('me/documents', [DocumentController::class, 'mine'])->name('documents.mine');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document')->name('documents.show');
    Route::get('documents/{document}/file', [DocumentController::class, 'download'])->whereNumber('document')->name('documents.file');
    Route::post('documents/{document}/acknowledge', [DocumentController::class, 'acknowledge'])
        ->whereNumber('document')->name('documents.acknowledge');
    Route::post('documents/{document}/reject', [DocumentController::class, 'reject'])->whereNumber('document')->name('documents.reject');

    Route::middleware('can:'.DocumentsServiceProvider::MANAGE)->group(function (): void {
        Route::get('documents/templates', [DocumentTemplateController::class, 'index'])->name('documents.templates.index');
        Route::post('documents/templates', [DocumentTemplateController::class, 'store'])->name('documents.templates.store');
        Route::post('documents/templates/preview', [DocumentTemplateController::class, 'preview'])->name('documents.templates.preview');
        Route::patch('documents/templates/{template}', [DocumentTemplateController::class, 'update'])
            ->whereNumber('template')->name('documents.templates.update');

        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::patch('documents/{document}', [DocumentController::class, 'update'])->whereNumber('document')->name('documents.update');
        Route::post('documents/{document}/file', [DocumentController::class, 'upload'])->whereNumber('document')->name('documents.upload');
        Route::post('documents/{document}/send', [DocumentController::class, 'send'])->whereNumber('document')->name('documents.send');
    });
});
