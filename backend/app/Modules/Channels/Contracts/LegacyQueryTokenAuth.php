<?php

declare(strict_types=1);

namespace App\Modules\Channels\Contracts;

use App\Modules\Integrations\DTO\IntegrationConfig;
use Illuminate\Http\Request;

/**
 * An adapter that may still accept the deprecated shared token in the URL (?token=…) behind an explicit integration
 * flag (telephony, HRM-26). WebhookService uses it to mark accepted legacy deliveries as deprecated (integration log,
 * Deprecation response header) and to explain a rejection; the admin page shows a warning while the flag is on.
 */
interface LegacyQueryTokenAuth
{
    /** The integration flag that allows ?token= is on. */
    public function queryTokenAllowed(IntegrationConfig $config): bool;

    /** The request carries a token in its URL (whatever its value). */
    public function usesQueryToken(Request $request): bool;
}
