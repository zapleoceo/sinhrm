<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

/** Spending since the start of the day (UTC): provider submissions (retries included) and cost reported by the provider. */
final readonly class AiUsage
{
    public function __construct(
        public int $requests,
        public float $costUsd,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $tokensCached = 0,
    ) {}
}
