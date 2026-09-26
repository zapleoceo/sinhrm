<?php

declare(strict_types=1);

namespace App\Modules\Channels\Contracts;

use App\Modules\Integrations\DTO\IntegrationConfig;
use Illuminate\Http\Request;

/** A provider that verifies the webhook URL with a GET request (WhatsApp hub.challenge). */
interface HandshakeResponder
{
    /** The challenge to echo back, or null when the verify token does not match (→ 403). */
    public function handshake(Request $request, IntegrationConfig $config): ?string;
}
