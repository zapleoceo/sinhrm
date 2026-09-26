<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Recruiting\Http\Controllers\AcquisitionChannelController;
use App\Modules\Recruiting\Http\Controllers\ApplicationController;
use App\Modules\Recruiting\Http\Controllers\CandidateController;
use App\Modules\Recruiting\Http\Controllers\ExtensionController;
use App\Modules\Recruiting\Http\Controllers\InboxController;
use App\Modules\Recruiting\Http\Controllers\PipelineController;
use App\Modules\Recruiting\Http\Controllers\ReportController;
use App\Modules\Recruiting\Http\Controllers\VacancyController;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Recruiting\Services\ExtensionTokenService;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// /api/* of the Recruiting module. Reading: any active user, limited to their scope (RecruitingScope);
// writing: FormRequest::authorize() → entity policies (Policies/*). DELETE only for UTM rules and channel costs (managers).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('pipelines', [PipelineController::class, 'index'])->name('recruiting.pipelines.index');
    Route::post('pipelines', [PipelineController::class, 'store'])
        ->middleware('can:'.RecruitingServiceProvider::MANAGE)->name('recruiting.pipelines.store');
    Route::get('reject-reasons', [PipelineController::class, 'rejectReasons'])->name('recruiting.reject-reasons.index');
    Route::middleware('can:'.RecruitingServiceProvider::MANAGE)->group(function (): void {
        Route::post('reject-reasons', [PipelineController::class, 'storeRejectReason'])->name('recruiting.reject-reasons.store');
        Route::patch('reject-reasons/{rejectReason}', [PipelineController::class, 'updateRejectReason'])
            ->whereNumber('rejectReason')->name('recruiting.reject-reasons.update');
    });

    // Acquisition channels (tz3): read by everyone (candidate form, filters); the dictionary, UTM rules and costs — managers.
    Route::get('acquisition-channels', [AcquisitionChannelController::class, 'index'])->name('recruiting.channels.index');
    Route::middleware('can:'.RecruitingServiceProvider::MANAGE)->prefix('acquisition-channels')->group(function (): void {
        Route::post('/', [AcquisitionChannelController::class, 'store'])->name('recruiting.channels.store');
        Route::post('resolve', [AcquisitionChannelController::class, 'preview'])->name('recruiting.channels.preview');
        Route::patch('{channel}', [AcquisitionChannelController::class, 'update'])->whereNumber('channel')->name('recruiting.channels.update');
        Route::post('{channel}/utm-rules', [AcquisitionChannelController::class, 'addRule'])->whereNumber('channel')->name('recruiting.channels.rules.store');
        Route::delete('utm-rules/{rule}', [AcquisitionChannelController::class, 'deleteRule'])->whereNumber('rule')->name('recruiting.channels.rules.destroy');
        Route::post('{channel}/costs', [AcquisitionChannelController::class, 'addCost'])->whereNumber('channel')->name('recruiting.channels.costs.store');
        Route::delete('costs/{cost}', [AcquisitionChannelController::class, 'deleteCost'])->whereNumber('cost')->name('recruiting.channels.costs.destroy');
    });

    Route::get('vacancies', [VacancyController::class, 'index'])->name('recruiting.vacancies.index');
    Route::post('vacancies', [VacancyController::class, 'store'])->name('recruiting.vacancies.store');
    Route::get('vacancies/{vacancy}', [VacancyController::class, 'show'])->whereNumber('vacancy')->name('recruiting.vacancies.show');
    Route::patch('vacancies/{vacancy}', [VacancyController::class, 'update'])->whereNumber('vacancy')->name('recruiting.vacancies.update');
    Route::get('vacancies/{vacancy}/board', [VacancyController::class, 'board'])->whereNumber('vacancy')->name('recruiting.vacancies.board');
    Route::get('vacancies/{vacancy}/sources', [VacancyController::class, 'sources'])->whereNumber('vacancy')->name('recruiting.vacancies.sources');
    Route::post('vacancies/{vacancy}/applications', [VacancyController::class, 'apply'])
        ->whereNumber('vacancy')->name('recruiting.vacancies.apply');

    Route::get('candidates', [CandidateController::class, 'index'])->name('recruiting.candidates.index');
    Route::post('candidates', [CandidateController::class, 'store'])->name('recruiting.candidates.store');
    Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->whereNumber('candidate')->name('recruiting.candidates.show');
    Route::patch('candidates/{candidate}', [CandidateController::class, 'update'])->whereNumber('candidate')->name('recruiting.candidates.update');
    Route::get('candidates/{candidate}/timeline', [CandidateController::class, 'timeline'])
        ->whereNumber('candidate')->name('recruiting.candidates.timeline');
    Route::post('candidates/{candidate}/touchpoints', [CandidateController::class, 'logTouch'])
        ->whereNumber('candidate')->name('recruiting.candidates.touchpoints');

    Route::post('applications/{application}/move', [ApplicationController::class, 'move'])
        ->whereNumber('application')->name('recruiting.applications.move');
    Route::get('recruiting/stale', [ApplicationController::class, 'stale'])->name('recruiting.stale');

    Route::get('inbox', [InboxController::class, 'index'])->name('recruiting.inbox.index');
    Route::post('inbox/{touchpoint}/link', [InboxController::class, 'link'])->whereNumber('touchpoint')->name('recruiting.inbox.link');
    Route::post('inbox/{touchpoint}/create-candidate', [InboxController::class, 'createCandidate'])
        ->whereNumber('touchpoint')->name('recruiting.inbox.create-candidate');

    Route::prefix('reports')->group(function (): void {
        Route::get('touches', [ReportController::class, 'touches'])->name('recruiting.reports.touches');
        Route::get('funnel', [ReportController::class, 'funnel'])->name('recruiting.reports.funnel');
        Route::get('sources', [ReportController::class, 'sources'])->name('recruiting.reports.sources');
        Route::get('reject-reasons', [ReportController::class, 'rejectReasons'])->name('recruiting.reports.reject-reasons');
    });
});

// Browser extension token: managed from the SPA session (a clipper token cannot reach these — see the provider).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('me/extension-token', [ExtensionController::class, 'tokenStatus'])->name('recruiting.extension-token.show');
    Route::post('me/extension-token', [ExtensionController::class, 'issueToken'])->name('recruiting.extension-token.issue');
    Route::delete('me/extension-token', [ExtensionController::class, 'revokeToken'])->name('recruiting.extension-token.revoke');
});

// /api/clipper/* — the only routes that accept the extension's bearer token (ability "clipper"); CORS in config/cors.php.
Route::prefix('clipper')
    ->middleware(['auth:sanctum', CheckAbilities::class.':'.ExtensionTokenService::ABILITY, EnsureUserIsActive::class, 'throttle:clipper'])
    ->group(function (): void {
        Route::get('me', [ExtensionController::class, 'me'])->name('recruiting.clipper.me');
        Route::post('candidates', [ExtensionController::class, 'clip'])->name('recruiting.clipper.candidates');
    });
