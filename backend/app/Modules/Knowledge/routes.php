<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Knowledge\Http\Controllers\KnowledgeController;
use App\Modules\Knowledge\Providers\KnowledgeServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/knowledge/* — reading: published articles for their audience (admins also drafts); writing: knowledge-manage.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('categories', [KnowledgeController::class, 'categories'])->name('knowledge.categories.index');
    Route::get('articles', [KnowledgeController::class, 'index'])->name('knowledge.articles.index');
    Route::get('articles/{article}', [KnowledgeController::class, 'show'])->whereNumber('article')->name('knowledge.articles.show');
    Route::post('articles/{article}/vote', [KnowledgeController::class, 'vote'])->whereNumber('article')->name('knowledge.articles.vote');

    Route::middleware('can:'.KnowledgeServiceProvider::MANAGE)->group(function (): void {
        Route::post('categories', [KnowledgeController::class, 'storeCategory'])->name('knowledge.categories.store');
        Route::patch('categories/{category}', [KnowledgeController::class, 'updateCategory'])->whereNumber('category')->name('knowledge.categories.update');
        Route::post('articles', [KnowledgeController::class, 'store'])->name('knowledge.articles.store');
        Route::patch('articles/{article}', [KnowledgeController::class, 'update'])->whereNumber('article')->name('knowledge.articles.update');
        Route::get('articles/{article}/versions', [KnowledgeController::class, 'versions'])->whereNumber('article')->name('knowledge.articles.versions');
    });
});
