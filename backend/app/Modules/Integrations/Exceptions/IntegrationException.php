<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation of the integrations admin; rendered as {message, code} with its HTTP status. */
final class IntegrationException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    public static function checkNotSupported(): self
    {
        return new self('check_not_supported', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
