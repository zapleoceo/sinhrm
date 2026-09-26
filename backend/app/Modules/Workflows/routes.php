<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Workflows\Http\Controllers\WorkflowRunController;
use App\Modules\Workflows\Http\Controllers\WorkflowTemplateController;
use App\Modules\Workflows\Providers\WorkflowsServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/workflows/* — templates and start/cancel/retry: gate workflows-manage (superadmin, admin — they act as HR);
// runs: admin all, managers their people (WorkflowRunService); complete/skip a step: its assignee or an admin.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('runs', [WorkflowRunController::class, 'index'])->name('workflows.runs.index');
    Route::get('runs/{workflowRun}', [WorkflowRunController::class, 'show'])->whereNumber('workflowRun')->name('workflows.runs.show');
    Route::post('runs/{workflowRun}/steps/{runStep}/complete', [WorkflowRunController::class, 'complete'])
        ->whereNumber('workflowRun')->whereNumber('runStep')->name('workflows.steps.complete');
    Route::post('runs/{workflowRun}/steps/{runStep}/skip', [WorkflowRunController::class, 'skip'])
        ->whereNumber('workflowRun')->whereNumber('runStep')->name('workflows.steps.skip');

    Route::middleware('can:'.WorkflowsServiceProvider::MANAGE)->group(function (): void {
        Route::get('templates', [WorkflowTemplateController::class, 'index'])->name('workflows.templates.index');
        Route::post('templates', [WorkflowTemplateController::class, 'store'])->name('workflows.templates.store');
        Route::get('templates/{workflowTemplate}', [WorkflowTemplateController::class, 'show'])
            ->whereNumber('workflowTemplate')->name('workflows.templates.show');
        Route::put('templates/{workflowTemplate}', [WorkflowTemplateController::class, 'update'])
            ->whereNumber('workflowTemplate')->name('workflows.templates.update');
        Route::delete('templates/{workflowTemplate}', [WorkflowTemplateController::class, 'destroy'])
            ->whereNumber('workflowTemplate')->name('workflows.templates.destroy');
        Route::post('templates/{workflowTemplate}/steps/reorder', [WorkflowTemplateController::class, 'reorder'])
            ->whereNumber('workflowTemplate')->name('workflows.templates.reorder');
        Route::put('templates/{workflowTemplate}/webhook-secret', [WorkflowTemplateController::class, 'webhookSecret'])
            ->whereNumber('workflowTemplate')->name('workflows.templates.webhook-secret');

        Route::post('runs', [WorkflowRunController::class, 'store'])->name('workflows.runs.store');
        Route::post('runs/{workflowRun}/cancel', [WorkflowRunController::class, 'cancel'])
            ->whereNumber('workflowRun')->name('workflows.runs.cancel');
        Route::post('runs/{workflowRun}/steps/{runStep}/retry', [WorkflowRunController::class, 'retry'])
            ->whereNumber('workflowRun')->whereNumber('runStep')->name('workflows.steps.retry');
    });
});
