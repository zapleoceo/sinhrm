<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// API-only application: the SPA lives in frontend/ and is served by Vercel.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'docs' => '/api/health']));
