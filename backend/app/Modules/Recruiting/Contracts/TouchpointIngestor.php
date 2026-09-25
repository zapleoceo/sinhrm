<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\IncomingMessage;
use App\Modules\Recruiting\Models\Touchpoint;

/**
 * Entry point for integrations (telephony, Telegram, WhatsApp, Viber, e-mail): stores a captured message as a
 * touchpoint. The sender is matched to a candidate by phone / e-mail / Telegram; no match → it stays in the inbox
 * (candidate_id null). Idempotent by (channel, externalId): a repeated delivery returns the stored touchpoint.
 */
interface TouchpointIngestor
{
    public function ingest(IncomingMessage $message): Touchpoint;
}
