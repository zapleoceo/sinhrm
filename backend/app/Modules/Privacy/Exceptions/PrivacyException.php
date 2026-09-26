<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A personal-data request that can't run: unknown subject (404) or a module blocker such as not_terminated (409). */
final class PrivacyException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    public static function blocked(string $code): self
    {
        return new self($code, $code === 'not_found' ? 404 : 409);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
