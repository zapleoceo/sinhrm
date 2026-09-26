<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Channels\Http\Controllers\ChannelAdminController;
use App\Modules\Channels\Http\Controllers\ChannelMessageController;
use App\Modules\Channels\Http\Controllers\WebhookController;
use App\Modules\Channels\Http\Middleware\LimitWebhookBody;
use App\Modules\Channels\Providers\ChannelsServiceProvider;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use Illuminate\Support\Facades\Route;

// Provider webhooks: no session, verified per provider (signature / secret header / ?token=), rate limited, ≤ 1 MB.
// {channelKey} = adapter key (unknown → 404); a switched-off integration → 404; bad signature → 403.
Route::middleware(['throttle:'.ChannelsServiceProvider::WEBHOOK_LIMITER, LimitWebhookBody::class])->group(function (): void {
    Route::post('webhooks/{channelKey}', [WebhookController::class, 'receive'])->name('channels.webhook');
    Route::get('webhooks/{channelKey}', [WebhookController::class, 'handshake'])->name('channels.webhook.handshake');
});

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('channels', [ChannelMessageController::class, 'index'])->name('channels.index');
    // Scoped by CandidatePolicy::update in the form requests.
    Route::post('candidates/{candidate}/messages', [ChannelMessageController::class, 'send'])
        ->whereNumber('candidate')->middleware('throttle:30,1')->name('channels.messages.send');
    Route::post('candidates/{candidate}/call', [ChannelMessageController::class, 'call'])
        ->whereNumber('candidate')->middleware('throttle:10,1')->name('channels.call');

    Route::middleware('can:'.IntegrationsServiceProvider::MANAGE_INTEGRATIONS)->prefix('channels')->group(function (): void {
        Route::get('admin', [ChannelAdminController::class, 'index'])->name('channels.admin');
        Route::post('{channelKey}/register-webhook', [ChannelAdminController::class, 'register'])->name('channels.register');
        Route::post('{channelKey}/test', [ChannelAdminController::class, 'test'])->middleware('throttle:10,1')->name('channels.test');
        Route::post('{channelKey}/simulate', [ChannelAdminController::class, 'simulate'])->middleware('throttle:30,1')->name('channels.simulate');
    });
});
