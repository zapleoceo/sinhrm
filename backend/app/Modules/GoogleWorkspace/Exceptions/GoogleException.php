<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Error of a Google call or of the connection; rendered as {message, code} with its HTTP status.
 * Codes never contain tokens, URLs with credentials or texts of upstream responses.
 */
final class GoogleException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    /** The service was never connected (or switched off by hand). */
    public static function notConnected(string $service): self
    {
        return new self('google_'.$service.'_not_connected', 422);
    }

    /** Refresh token revoked/expired (invalid_grant): a superadmin must connect again. */
    public static function reconnectRequired(): self
    {
        return new self('reconnect_required', 409);
    }

    public static function oauthNotConfigured(): self
    {
        return new self('google_oauth_not_configured', 422);
    }

    public static function unauthorized(): self
    {
        return new self('google_unauthorized', 502);
    }

    public static function forbidden(): self
    {
        return new self('google_forbidden', 502);
    }

    public static function notFound(): self
    {
        return new self('google_not_found', 422);
    }

    public static function httpError(int $status): self
    {
        return new self('google_http_'.$status, 502);
    }

    public static function unreachable(): self
    {
        return new self('google_unreachable', 502);
    }

    public static function badResponse(): self
    {
        return new self('google_bad_response', 502);
    }

    public static function invalidSheetUrl(): self
    {
        return new self('invalid_sheet_url', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
