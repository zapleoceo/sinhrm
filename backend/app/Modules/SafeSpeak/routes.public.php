<?php

declare(strict_types=1);

use App\Modules\SafeSpeak\Http\Controllers\PublicReportController;
use Illuminate\Support\Facades\Route;

// /api/safe-speak/public/* — anonymous. Loaded WITHOUT the "api"/"web" groups: no session is started or touched
// (the sessions table would keep the IP and user agent), no auth, no CSRF cookie. Rate limits by a hashed client
// bucket live in the service. Only JSON in and out.
Route::post('reports', [PublicReportController::class, 'submit'])->name('safe-speak.public.submit');
Route::post('follow-up', [PublicReportController::class, 'followUp'])->name('safe-speak.public.follow-up');
Route::post('reply', [PublicReportController::class, 'reply'])->name('safe-speak.public.reply');
