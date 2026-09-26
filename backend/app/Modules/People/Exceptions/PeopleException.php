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

    public static function alreadyDecided(): self
    {
        return new self('already_decided', 409);
    }

    /** Hire is offered only for applications on the pipeline's hire stage. */
    public static function notHired(): self
    {
        return new self('not_hired', 422);
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
