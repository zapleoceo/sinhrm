<?php

declare(strict_types=1);

namespace App\Modules\Workflows\DTO;

use App\Modules\Workflows\Enums\RunStepStatus;

/**
 * Result of an executor: done / skipped (with a reason code) / failed (error code) / waiting (a task was created,
 * the step stays pending until a person completes it). $result holds codes and ids only — never payloads or secrets.
 */
final readonly class StepOutcome
{
    /** @param  array<string, int|string|bool|null>  $result */
    private function __construct(public RunStepStatus $status, public bool $waiting, public array $result) {}

    /** @param  array<string, int|string|bool|null>  $result */
    public static function done(array $result = []): self
    {
        return new self(RunStepStatus::Done, false, $result);
    }

    /** @param  array<string, int|string|bool|null>  $result */
    public static function waiting(array $result): self
    {
        return new self(RunStepStatus::Pending, true, $result);
    }

    public static function skipped(string $reason): self
    {
        return new self(RunStepStatus::Skipped, false, ['reason' => $reason]);
    }

    public static function failed(string $error): self
    {
        return new self(RunStepStatus::Failed, false, ['error' => $error]);
    }
}
