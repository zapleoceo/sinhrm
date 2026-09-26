<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Pulse\Http\Controllers\MoodController;
use App\Modules\Pulse\Http\Controllers\SurveyController;
use App\Modules\Pulse\Http\Controllers\WaveController;
use App\Modules\Pulse\Providers\PulseServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/pulse/* — answering and own mood: every active user with an employee record; reports: admins and managers
// (own department / subtree, aggregates only); builder, waves, identified responses, mood settings: pulse-manage.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('my/waves', [WaveController::class, 'mine'])->name('pulse.my.waves');
    Route::get('waves/{id}/form', [WaveController::class, 'form'])->whereNumber('id')->name('pulse.waves.form');
    Route::post('waves/{id}/responses', [WaveController::class, 'respond'])->whereNumber('id')
        ->middleware('throttle:30,1')->name('pulse.waves.respond');
    Route::get('waves/{id}/results', [WaveController::class, 'results'])->whereNumber('id')->name('pulse.waves.results');
    Route::get('waves/{id}/compare', [WaveController::class, 'compare'])->whereNumber('id')->name('pulse.waves.compare');

    Route::get('mood/today', [MoodController::class, 'today'])->name('pulse.mood.today');
    Route::post('mood', [MoodController::class, 'store'])->middleware('throttle:30,1')->name('pulse.mood.store');
    Route::get('mood/me', [MoodController::class, 'me'])->name('pulse.mood.me');
    Route::get('mood/team', [MoodController::class, 'team'])->name('pulse.mood.team');
    Route::get('mood/settings', [MoodController::class, 'settings'])->name('pulse.mood.settings');

    Route::middleware('can:'.PulseServiceProvider::MANAGE)->group(function (): void {
        Route::put('mood/settings', [MoodController::class, 'updateSettings'])->name('pulse.mood.settings.update');
        Route::get('templates', [SurveyController::class, 'templates'])->name('pulse.templates');
        Route::get('surveys', [SurveyController::class, 'index'])->name('pulse.surveys.index');
        Route::post('surveys', [SurveyController::class, 'store'])->name('pulse.surveys.store');
        Route::get('surveys/{id}', [SurveyController::class, 'show'])->whereNumber('id')->name('pulse.surveys.show');
        Route::put('surveys/{id}', [SurveyController::class, 'update'])->whereNumber('id')->name('pulse.surveys.update');
        Route::delete('surveys/{id}', [SurveyController::class, 'destroy'])->whereNumber('id')->name('pulse.surveys.destroy');
        Route::get('surveys/{id}/waves', [SurveyController::class, 'waves'])->whereNumber('id')->name('pulse.waves.index');
        Route::post('surveys/{id}/waves', [SurveyController::class, 'storeWave'])->whereNumber('id')->name('pulse.waves.store');
        Route::get('waves/{waveId}', [SurveyController::class, 'showWave'])->whereNumber('waveId')->name('pulse.waves.show');
        Route::put('waves/{waveId}', [SurveyController::class, 'updateWave'])->whereNumber('waveId')->name('pulse.waves.update');
        Route::post('waves/{waveId}/close', [SurveyController::class, 'closeWave'])->whereNumber('waveId')->name('pulse.waves.close');
        Route::get('waves/{waveId}/responses', [SurveyController::class, 'responses'])->whereNumber('waveId')->name('pulse.waves.responses');
    });
});
