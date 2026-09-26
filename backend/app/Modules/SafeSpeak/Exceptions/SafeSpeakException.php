<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Rendered as {message, code} (+ retry_after for 429). Never carries the code, the IP or the report text. */
final class SafeSpeakException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly ?int $retryAfter = null)
    {
        parent::__construct($errorCode);
    }

    /** Wrong or unknown access code — the same answer for both (no oracle). */
    public static function invalidCode(): self
    {
        return new self('invalid_code', 404);
    }

    public static function tooManyAttempts(int $retryAfter): self
    {
        return new self('too_many_attempts', 429, $retryAfter);
    }

    public static function closed(): self
    {
        return new self('report_closed', 409);
    }

    public function render(): JsonResponse
    {
        $headers = $this->retryAfter === null ? [] : ['Retry-After' => (string) $this->retryAfter];

        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status, $headers);
    }
}
