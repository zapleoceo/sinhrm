<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

/**
 * A submitted job. $immediate carries the answer of providers that reply synchronously (OpenRouter), so the first
 * poll returns it without a second call.
 */
final readonly class AiJobRef
{
    public function __construct(
        public string $provider,
        public string $jobId,
        public int $pollAfterSeconds = 2,
        public ?AiResult $immediate = null,
    ) {}
}
