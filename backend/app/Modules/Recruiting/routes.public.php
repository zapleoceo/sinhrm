<?php

declare(strict_types=1);

use App\Modules\Recruiting\Http\Controllers\PublicCareerController;
use Illuminate\Support\Facades\Route;

// /api/public/* is the anonymous career page (/jobs). No session, no auth; JSON only. A disabled Recruiting module
// answers 404 to guests (EnsureModuleAccessible). The per-IP-hash limit of applications lives in CareerSiteService.
Route::get('vacancies', [PublicCareerController::class, 'index'])->name('recruiting.public.vacancies');
Route::get('vacancies/{slug}', [PublicCareerController::class, 'show'])
    ->where('slug', '[a-z0-9-]{1,150}')->name('recruiting.public.vacancies.show');
Route::post('vacancies/{slug}/apply', [PublicCareerController::class, 'apply'])
    ->where('slug', '[a-z0-9-]{1,150}')->middleware('throttle:30,1')->name('recruiting.public.vacancies.apply');
