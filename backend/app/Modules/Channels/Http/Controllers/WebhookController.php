<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Controllers;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public provider webhooks (no session): /api/webhooks/{key}. {channelKey} → adapter (unknown → 404). */
final class WebhookController
{
    public function __construct(private readonly WebhookService $service) {}

    /** RFC 9745 Deprecation value (@epoch of 2026-10-09, HRM-26) for deliveries that used the deprecated ?token=. */
    public const string DEPRECATED_SINCE = '@1791504000';

    public function receive(Request $request, ChannelAdapter $channelKey): JsonResponse
    {
        $result = $this->service->receive($channelKey, $request);

        return new JsonResponse($channelKey->acknowledge(), 200, $result->deprecatedAuth ? ['Deprecation' => self::DEPRECATED_SINCE] : []);
    }

    /** GET handshake (WhatsApp): echo hub.challenge as plain text. */
    public function handshake(Request $request, ChannelAdapter $channelKey): Response
    {
        return new Response($this->service->handshake($channelKey, $request), 200, ['Content-Type' => 'text/plain']);
    }
}
