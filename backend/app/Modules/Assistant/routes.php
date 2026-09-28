<?php

declare(strict_types=1);

use App\Modules\Assistant\Http\Controllers\AssistantController;
use App\Modules\Assistant\Http\Controllers\McpTokenController;
use App\Modules\Assistant\Providers\AssistantServiceProvider;
use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

// /api/assistant/* — session only (an MCP token gets 401 here: TokenScopes allows it on /api/mcp alone).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('status', [AssistantController::class, 'status'])->name('assistant.status');
    Route::post('turn', [AssistantController::class, 'turn'])
        ->middleware('throttle:'.AssistantServiceProvider::CHAT_LIMITER)->name('assistant.turn');
    Route::get('turns/{requestId}', [AssistantController::class, 'poll'])->whereNumber('requestId')->name('assistant.turns.show');
    Route::post('transcribe', [AssistantController::class, 'transcribe'])
        ->middleware('throttle:'.AssistantServiceProvider::VOICE_LIMITER)->name('assistant.transcribe');
    Route::get('transcriptions/{requestId}', [AssistantController::class, 'transcription'])->whereNumber('requestId')->name('assistant.transcriptions.show');

    Route::get('mcp-token', [McpTokenController::class, 'show'])->name('assistant.mcp-token.show');
    Route::post('mcp-token', [McpTokenController::class, 'issue'])->name('assistant.mcp-token.issue');
    Route::delete('mcp-token', [McpTokenController::class, 'revoke'])->name('assistant.mcp-token.revoke');
});
