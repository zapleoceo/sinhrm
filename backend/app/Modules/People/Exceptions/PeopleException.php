<?php

declare(strict_types=1);

namespace App\Modules\People\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in People; rendered as {message, code, ...extra} with its HTTP status. */
final class PeopleException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    /** The user has no employee record (self-service endpoints). */
    public static function noEmployee(): self
    {
        return new self('no_employee', 404);
    }

    /** manager_id would create a loop (self or someone below). */
    public static function managerCycle(): self
    {
        return new self('manager_cycle', 422);
    }

    public static function alreadyTerminated(): self
    {
        return new self('already_terminated', 409);
    }

    /** A termination is already scheduled for a future date: cancel it first. */
    public static function terminationScheduled(): self
    {
        return new self('termination_scheduled', 409);
    }

    /** Cancel: there is no scheduled (future) termination. */
    public static function terminationNotScheduled(): self
    {
        return new self('termination_not_scheduled', 409);
    }

    /** Restore: the employee is not terminated. */
    public static function notTerminated(): self
    {
        return new self('not_terminated', 409);
    }

    /** Restore: personal data was already erased (Privacy), the record cannot come back. */
    public static function anonymized(): self
    {
        return new self('anonymized', 409);
    }

    public static function alreadyDecided(): self
    {
        return new self('already_decided', 409);
    }

    /** Hire is offered only for applications on the pipeline's hire stage. */
    public static function notHired(): self
    {
        return new self('not_hired', 422);
    }

    /** Terminate: the handover colleague is unknown, terminated, invisible to the caller or the employee themself. */
    public static function invalidHandover(): self
    {
        return new self('invalid_handover', 422);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 403);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
