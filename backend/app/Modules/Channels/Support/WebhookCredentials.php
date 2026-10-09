<?php

declare(strict_types=1);

namespace App\Modules\Channels\Support;

use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * Shared, constant-time checks of webhook credentials (one place for every adapter):
 * - a shared token in a header: X-Webhook-Token: <token> or Authorization: Bearer <token>;
 * - an HMAC-SHA256 of the raw body in one fixed format per provider (WhatsApp "sha256=" + hex, Viber hex) or in
 *   X-Signature (telephony);
 * - the legacy ?token= of telephony (only behind the integration flag, see AbstractTelephonyAdapter).
 * Nothing here logs or returns the values it compares.
 */
final class WebhookCredentials
{
    public const string TOKEN_HEADER = 'X-Webhook-Token';

    public const string SIGNATURE_HEADER = 'X-Signature';

    /** Legacy query parameter of telephony webhooks (the value lands in proxy/access logs — deprecated). */
    public const string QUERY_TOKEN = 'token';

    /** Token from X-Webhook-Token, else from Authorization: Bearer; null when neither is sent. */
    public static function headerToken(Request $request): ?string
    {
        $token = $request->header(self::TOKEN_HEADER);
        if (is_string($token) && $token !== '') {
            return $token;
        }
        $bearer = $request->bearerToken();

        return is_string($bearer) && $bearer !== '' ? $bearer : null;
    }

    public static function tokenMatches(#[SensitiveParameter] string $secret, #[SensitiveParameter] mixed $given): bool
    {
        return $secret !== '' && is_string($given) && $given !== '' && hash_equals($secret, $given);
    }

    /**
     * HMAC-SHA256 of the raw body in $header, in exactly one format per provider (no guessing):
     * $prefix is required when set and forbidden when empty; $ignoreCase lets upper-case hex through.
     * - WhatsApp: "sha256=" + lower-case hex, exact (as Meta sends it);
     * - Viber: hex without prefix, any case;
     * - telephony X-Signature (default): hex without prefix, any case.
     */
    public static function signatureMatches(
        Request $request,
        #[SensitiveParameter] string $secret,
        string $header = self::SIGNATURE_HEADER,
        string $prefix = '',
        bool $ignoreCase = true,
    ): bool {
        $given = $request->header($header);
        if ($secret === '' || ! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals($prefix.hash_hmac('sha256', $request->getContent(), $secret), $ignoreCase ? strtolower($given) : $given);
    }

    /** The request carries a token in its URL (?token=…), whatever its value. */
    public static function hasQueryToken(Request $request): bool
    {
        return $request->query->has(self::QUERY_TOKEN);
    }
}
