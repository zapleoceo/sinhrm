<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;

/**
 * Webhook fields shared by the telephony definitions (Phonet, Ringostat, Binotel; HRM-26):
 * - "webhook_token": the shared secret the provider sends in a header (X-Webhook-Token / Authorization: Bearer) or uses
 *   as the HMAC-SHA256 key of X-Signature; compared in constant time by Channels\Adapters\AbstractTelephonyAdapter;
 * - "webhook_query_token": transitional flag (off by default for new connections) that still accepts the token in the
 *   URL (?token=…). The URL lands in proxy/access logs, so it is deprecated; existing connections got "on" by migration.
 */
final class TelephonyWebhookFields
{
    public const string TOKEN = 'webhook_token';

    public const string QUERY_TOKEN_FLAG = 'webhook_query_token';

    public const string ON = 'on';

    public const string OFF = 'off';

    /** @return list<FieldSpec> */
    public static function fields(): array
    {
        return [
            FieldSpec::secret(self::TOKEN, required: false),
            FieldSpec::select(self::QUERY_TOKEN_FLAG, [self::OFF, self::ON], default: self::OFF),
        ];
    }
}
