<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Perform\Http\Controllers\DevelopmentPlanController;
use App\Modules\Perform\Http\Controllers\FeedbackController;
use App\Modules\Perform\Http\Controllers\KpiController;
use App\Modules\Perform\Http\Controllers\ObjectiveController;
use App\Modules\Perform\Http\Controllers\OneOnOneController;
use App\Modules\Perform\Http\Controllers\ReviewController;
use App\Modules\Perform\Http\Controllers\ReviewSetupController;
use App\Modules\Perform\Providers\PerformServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/perform/* — every active user; the services scope data (admin all, managers their subtree, employees own items).
// Review setup and 1:1 template writes: gate perform-manage (superadmin, admin).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('one-on-ones', [OneOnOneController::class, 'index'])->name('perform.one-on-ones.index');
    Route::post('one-on-ones', [OneOnOneController::class, 'store'])->name('perform.one-on-ones.store');
    Route::get('one-on-ones/{id}', [OneOnOneController::class, 'show'])->whereNumber('id')->name('perform.one-on-ones.show');
    Route::patch('one-on-ones/{id}', [OneOnOneController::class, 'update'])->whereNumber('id')->name('perform.one-on-ones.update');
    Route::delete('one-on-ones/{id}', [OneOnOneController::class, 'destroy'])->whereNumber('id')->name('perform.one-on-ones.destroy');
    Route::get('one-on-one-templates', [OneOnOneController::class, 'templates'])->name('perform.templates.index');

    Route::get('objectives', [ObjectiveController::class, 'index'])->name('perform.objectives.index');
    Route::post('objectives', [ObjectiveController::class, 'store'])->name('perform.objectives.store');
    Route::get('objectives/{id}', [ObjectiveController::class, 'show'])->whereNumber('id')->name('perform.objectives.show');
    Route::put('objectives/{id}', [ObjectiveController::class, 'update'])->whereNumber('id')->name('perform.objectives.update');
    Route::delete('objectives/{id}', [ObjectiveController::class, 'destroy'])->whereNumber('id')->name('perform.objectives.destroy');
    Route::post('objectives/{id}/check-ins', [ObjectiveController::class, 'checkIn'])->whereNumber('id')->name('perform.objectives.check-in');

    Route::get('kpis', [KpiController::class, 'index'])->name('perform.kpis.index');
    Route::post('kpis', [KpiController::class, 'store'])->name('perform.kpis.store');
    Route::put('kpis/{id}', [KpiController::class, 'update'])->whereNumber('id')->name('perform.kpis.update');
    Route::delete('kpis/{id}', [KpiController::class, 'destroy'])->whereNumber('id')->name('perform.kpis.destroy');

    Route::get('feedback', [FeedbackController::class, 'index'])->name('perform.feedback.index');
    Route::post('feedback', [FeedbackController::class, 'store'])->name('perform.feedback.store');

    Route::get('development-plans', [DevelopmentPlanController::class, 'index'])->name('perform.plans.index');
    Route::post('development-plans', [DevelopmentPlanController::class, 'store'])->name('perform.plans.store');
    Route::put('development-plans/{id}', [DevelopmentPlanController::class, 'update'])->whereNumber('id')->name('perform.plans.update');
    Route::delete('development-plans/{id}', [DevelopmentPlanController::class, 'destroy'])->whereNumber('id')->name('perform.plans.destroy');
    Route::patch('development-plans/{id}/actions/{actionId}', [DevelopmentPlanController::class, 'toggleAction'])
        ->whereNumber('id')->where('actionId', '[A-Za-z0-9_-]{1,32}')->name('perform.plans.action');

    Route::get('review/assignments', [ReviewController::class, 'mine'])->name('perform.review.mine');
    Route::get('review/assignments/{id}', [ReviewController::class, 'show'])->whereNumber('id')->name('perform.review.show');
    Route::post('review/assignments/{id}/submit', [ReviewController::class, 'submit'])->whereNumber('id')->name('perform.review.submit');
    Route::get('review/cycles/{cycleId}/results/{employeeId}', [ReviewController::class, 'results'])
        ->whereNumber('cycleId')->whereNumber('employeeId')->name('perform.review.results');
    Route::get('review/employees/{employeeId}/results', [ReviewController::class, 'employeeResults'])
        ->whereNumber('employeeId')->name('perform.review.employee-results');

    Route::middleware('can:'.PerformServiceProvider::MANAGE)->group(function (): void {
        Route::post('one-on-one-templates', [OneOnOneController::class, 'storeTemplate'])->name('perform.templates.store');
        Route::put('one-on-one-templates/{id}', [OneOnOneController::class, 'updateTemplate'])->whereNumber('id')->name('perform.templates.update');
        Route::delete('one-on-one-templates/{id}', [OneOnOneController::class, 'destroyTemplate'])->whereNumber('id')->name('perform.templates.destroy');

        Route::get('review/scales', [ReviewSetupController::class, 'scales'])->name('perform.scales.index');
        Route::post('review/scales', [ReviewSetupController::class, 'storeScale'])->name('perform.scales.store');
        Route::put('review/scales/{id}', [ReviewSetupController::class, 'updateScale'])->whereNumber('id')->name('perform.scales.update');
        Route::delete('review/scales/{id}', [ReviewSetupController::class, 'destroyScale'])->whereNumber('id')->name('perform.scales.destroy');
        Route::get('review/competencies', [ReviewSetupController::class, 'competencies'])->name('perform.competencies.index');
        Route::post('review/competencies', [ReviewSetupController::class, 'storeCompetency'])->name('perform.competencies.store');
        Route::put('review/competencies/{id}', [ReviewSetupController::class, 'updateCompetency'])->whereNumber('id')->name('perform.competencies.update');
        Route::delete('review/competencies/{id}', [ReviewSetupController::class, 'destroyCompetency'])->whereNumber('id')->name('perform.competencies.destroy');
        Route::get('review/cycles', [ReviewSetupController::class, 'cycles'])->name('perform.cycles.index');
        Route::post('review/cycles', [ReviewSetupController::class, 'storeCycle'])->name('perform.cycles.store');
        Route::get('review/cycles/{id}', [ReviewSetupController::class, 'showCycle'])->whereNumber('id')->name('perform.cycles.show');
        Route::put('review/cycles/{id}', [ReviewSetupController::class, 'updateCycle'])->whereNumber('id')->name('perform.cycles.update');
        Route::delete('review/cycles/{id}', [ReviewSetupController::class, 'destroyCycle'])->whereNumber('id')->name('perform.cycles.destroy');
        Route::post('review/cycles/{id}/activate', [ReviewSetupController::class, 'activate'])->whereNumber('id')->name('perform.cycles.activate');
        Route::post('review/cycles/{id}/close', [ReviewSetupController::class, 'close'])->whereNumber('id')->name('perform.cycles.close');
        Route::post('review/cycles/{id}/assignments', [ReviewSetupController::class, 'addAssignment'])->whereNumber('id')->name('perform.cycles.assign');
    });
});
