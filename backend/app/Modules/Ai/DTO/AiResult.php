<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

/** State of a job: pending (poll again), done (text + usage) or error (a code, never the provider's message). */
final readonly class AiResult
{
    public const string PENDING = 'pending';

    public const string DONE = 'done';

    public const string ERROR = 'error';

    private function __construct(
        public string $status,
        public ?string $text = null,
        public ?string $model = null,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $tokensCached = 0,
        public float $costUsd = 0.0,
        public ?string $finishReason = null,
        public ?string $error = null,
        public int $pollAfterSeconds = 2,
    ) {}

    public static function pending(int $pollAfterSeconds = 2): self
    {
        return new self(self::PENDING, pollAfterSeconds: max(1, $pollAfterSeconds));
    }

    public static function done(
        string $text,
        ?string $model = null,
        int $tokensIn = 0,
        int $tokensOut = 0,
        int $tokensCached = 0,
        float $costUsd = 0.0,
        ?string $finishReason = null,
    ): self {
        return new self(self::DONE, $text, $model, max(0, $tokensIn), max(0, $tokensOut), max(0, $tokensCached), max(0.0, $costUsd), $finishReason);
    }

    public static function error(string $code): self
    {
        return new self(self::ERROR, error: $code);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }
}
