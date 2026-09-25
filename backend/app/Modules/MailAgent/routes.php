<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use App\Modules\MailAgent\Http\Controllers\MailAgentController;
use Illuminate\Support\Facades\Route;

// /api/mail/* — superadmin only (the same gate as the integrations admin).
Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS])
    ->group(function (): void {
        Route::get('status', [MailAgentController::class, 'status'])->name('mail.status');
        Route::post('sync', [MailAgentController::class, 'sync'])->name('mail.sync');
        Route::get('messages', [MailAgentController::class, 'messages'])->name('mail.messages');

        Route::get('rules', [MailAgentController::class, 'rules'])->name('mail.rules.index');
        Route::post('rules', [MailAgentController::class, 'storeRule'])->name('mail.rules.store');
        Route::patch('rules/{rule}', [MailAgentController::class, 'updateRule'])->whereNumber('rule')->name('mail.rules.update');
        Route::delete('rules/{rule}', [MailAgentController::class, 'deleteRule'])->whereNumber('rule')->name('mail.rules.delete');

        Route::get('unknown-senders', [MailAgentController::class, 'unknown'])->name('mail.unknown.index');
        Route::post('unknown-senders/{sender}/assign', [MailAgentController::class, 'assign'])
            ->whereNumber('sender')->name('mail.unknown.assign');
        Route::delete('unknown-senders/{sender}', [MailAgentController::class, 'dismiss'])
            ->whereNumber('sender')->name('mail.unknown.dismiss');
    });
