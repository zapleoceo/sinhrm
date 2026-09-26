<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

/**
 * What a caller gets back from AiService::run(): done (validated data), deferred (still running — the ai.poll job
 * finishes it and the purpose handler applies the result) or failed (error code).
 */
final readonly class AiOutcome
{
    public const string DONE = 'done';

    public const string DEFERRED = 'deferred';

    public const string FAILED = 'failed';

    /** @param  array<string, mixed>|null  $data */
    private function __construct(
        public string $status,
        public int $requestId,
        public ?array $data = null,
        public ?string $error = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function done(int $requestId, array $data): self
    {
        return new self(self::DONE, $requestId, $data);
    }

    public static function deferred(int $requestId): self
    {
        return new self(self::DEFERRED, $requestId);
    }

    public static function failed(int $requestId, string $error): self
    {
        return new self(self::FAILED, $requestId, error: $error);
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    public function isDeferred(): bool
    {
        return $this->status === self::DEFERRED;
    }
}
