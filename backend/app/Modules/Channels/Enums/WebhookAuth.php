<?php

declare(strict_types=1);

namespace App\Modules\Channels\Enums;

/** How a webhook request proves it comes from the provider. */
enum WebhookAuth: string
{
    /** Secret header set at registration (Telegram X-Telegram-Bot-Api-Secret-Token). */
    case HeaderSecret = 'header_secret';
    /** HMAC-SHA256 of the raw body (WhatsApp X-Hub-Signature-256, Viber X-Viber-Content-Signature). */
    case Hmac = 'hmac';
    /**
     * Shared token in a header (X-Webhook-Token / Authorization: Bearer) or X-Signature HMAC-SHA256 of the body
     * (telephony). The legacy ?token= is not a mode of its own: a transitional flag of the integration (HRM-26).
     */
    case HeaderToken = 'header_token';
}
