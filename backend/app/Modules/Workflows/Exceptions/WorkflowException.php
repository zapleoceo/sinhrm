<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Workflows; rendered as {message, code, ...extra} with its HTTP status. */
final class WorkflowException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    /** start_workflow nesting deeper than WorkflowRunService::MAX_DEPTH. */
    public static function depthLimit(): self
    {
        return new self('depth_limit', 422);
    }

    public static function runNotRunning(): self
    {
        return new self('run_not_running', 409);
    }

    /** Complete / skip a step that is already finished; retry a step that has not failed. */
    public static function stepNotOpen(): self
    {
        return new self('step_not_open', 409);
    }

    public static function stepNotFailed(): self
    {
        return new self('step_not_failed', 409);
    }

    /** Delete a template that already has runs (history): deactivate it instead. */
    public static function hasRuns(): self
    {
        return new self('has_runs', 409);
    }

    public static function terminated(): self
    {
        return new self('employee_terminated', 422);
    }

    /** Reorder with a list that is not exactly the template's step ids. */
    public static function invalidOrder(): self
    {
        return new self('invalid_order', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
