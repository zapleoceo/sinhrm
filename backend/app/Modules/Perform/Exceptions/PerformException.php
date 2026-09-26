<?php

declare(strict_types=1);

namespace App\Modules\Perform\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Perform; rendered as {message, code} with its HTTP status. */
final class PerformException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    /** The user has no employee record: 1:1s, feedback and reviews need one. */
    public static function noEmployee(): self
    {
        return new self('no_employee', 422);
    }

    public static function selfTarget(): self
    {
        return new self('self_target', 422);
    }

    /** Parent objective would create a loop (A → B → A) or is the objective itself. */
    public static function alignmentCycle(): self
    {
        return new self('alignment_cycle', 422);
    }

    public static function requestNotOpen(): self
    {
        return new self('request_not_open', 409);
    }

    public static function cycleNotDraft(): self
    {
        return new self('cycle_not_draft', 409);
    }

    public static function cycleNotActive(): self
    {
        return new self('cycle_not_active', 409);
    }

    public static function alreadySubmitted(): self
    {
        return new self('already_submitted', 409);
    }

    /** Answers must rate every competency of the cycle with a value of its scale. */
    public static function invalidAnswers(): self
    {
        return new self('invalid_answers', 422);
    }

    /** A scale / competency is still used and cannot be deleted. */
    public static function inUse(): self
    {
        return new self('in_use', 409);
    }

    public static function duplicate(): self
    {
        return new self('duplicate', 409);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
