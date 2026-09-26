<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in HiringRequests; rendered as {message, code, ...extra} with its HTTP status. */
final class HiringException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    /** The action is not allowed in the request's current status (someone decided first, already closed, …). */
    public static function invalidStatus(string $status): self
    {
        return new self('invalid_status', 409, ['status' => $status]);
    }

    /** Replacement needs the replaced employee. */
    public static function replacedEmployeeRequired(): self
    {
        return new self('replaced_employee_required', 422);
    }

    /**
     * A required configurable form field is empty on submit; fields = the missing keys.
     *
     * @param  list<string>  $fields
     */
    public static function requiredFields(array $fields): self
    {
        return new self('required_fields', 422, ['fields' => $fields]);
    }

    /** A value of a configurable field has the wrong type / is not one of the options. */
    public static function invalidField(string $field): self
    {
        return new self('invalid_field', 422, ['field' => $field]);
    }

    public static function salaryRange(): self
    {
        return new self('salary_range', 422);
    }

    /** The vacancy is already linked to another request. */
    public static function vacancyTaken(): self
    {
        return new self('vacancy_taken', 409);
    }

    /** Only an open vacancy of the request's branch can be linked. */
    public static function vacancyNotLinkable(): self
    {
        return new self('vacancy_not_linkable', 422);
    }

    /** The recruiter of the new vacancy must be an active user. */
    public static function invalidRecruiter(): self
    {
        return new self('invalid_recruiter', 422);
    }

    /** The route must have at least one step; user steps need a user, role steps a role. */
    public static function invalidRoute(): self
    {
        return new self('invalid_route', 422);
    }

    public static function noDefaultPipeline(): self
    {
        return new self('no_default_pipeline', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
