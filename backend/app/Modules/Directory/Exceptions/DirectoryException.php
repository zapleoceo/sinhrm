<?php

declare(strict_types=1);

namespace App\Modules\Directory\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Business error of the directory; rendered as {message, code} with its HTTP status.
 * Codes never contain secret values, URLs or texts of upstream exceptions.
 */
final class DirectoryException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    public static function integrationNotConfigured(): self
    {
        return new self('integration_not_configured', 422);
    }

    /** OutboundUrlGuard refused the base URL: sintegrum_{invalid_url|blocked_host|blocked_port|unresolved_host}. */
    public static function urlBlocked(string $reason): self
    {
        return new self('sintegrum_'.$reason, 422);
    }

    public static function unauthorized(): self
    {
        return new self('sintegrum_unauthorized', 502);
    }

    public static function httpError(int $status): self
    {
        return new self('sintegrum_http_'.$status, 502);
    }

    public static function unreachable(): self
    {
        return new self('sintegrum_unreachable', 502);
    }

    public static function badResponse(): self
    {
        return new self('sintegrum_bad_response', 502);
    }

    public static function timeout(): self
    {
        return new self('sintegrum_timeout', 504);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
