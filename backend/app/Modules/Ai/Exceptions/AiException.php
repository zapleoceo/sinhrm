<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * AI refused or failed; rendered as {message, code} with its HTTP status. Codes only — never provider messages,
 * prompts or keys (docs/modules/ai.md, "Коди помилок").
 */
final class AiException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    /** The global AI switch (AiPolicy) is off. */
    public static function disabled(): self
    {
        return new self('ai_disabled', 422);
    }

    /** No project key, or the AI Broker integration is "off". */
    public static function notConfigured(): self
    {
        return new self('ai_not_configured', 422);
    }

    /** This purpose is switched off in the AI settings. */
    public static function purposeDisabled(): self
    {
        return new self('ai_purpose_disabled', 422);
    }

    /** Daily request or cost cap reached (counted from ai_requests). */
    public static function budgetExceeded(): self
    {
        return new self('ai_budget_exceeded', 429);
    }

    /** Transport/HTTP problem with the provider; $code is a short machine code (http_401, connection_failed, …). */
    public static function provider(string $code): self
    {
        return new self('ai_provider_'.$code, 502);
    }

    public static function invalidOutput(): self
    {
        return new self('ai_invalid_output', 502);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
