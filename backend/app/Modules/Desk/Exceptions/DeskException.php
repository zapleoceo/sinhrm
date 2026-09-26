<?php

declare(strict_types=1);

namespace App\Modules\Desk\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Desk; rendered as {message, code} with its HTTP status. */
final class DeskException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    /** The user has no employee record, so there is nobody to open the case for. */
    public static function noEmployee(): self
    {
        return new self('no_employee', 422);
    }

    public static function categoryInactive(): self
    {
        return new self('category_inactive', 422);
    }

    /** Assignees are HR staff (superadmin/admin/hr_manager) only. */
    public static function invalidAssignee(): self
    {
        return new self('invalid_assignee', 422);
    }

    /** A linked knowledge article must exist and be published. */
    public static function articleNotFound(): self
    {
        return new self('article_not_found', 422);
    }

    /** Comments and files on a closed case are refused (reopen it first). */
    public static function caseClosed(): self
    {
        return new self('case_closed', 409);
    }

    public static function invalidFile(): self
    {
        return new self('invalid_file', 422);
    }

    public static function fileTooLarge(): self
    {
        return new self('file_too_large', 422);
    }

    public static function tooManyFiles(): self
    {
        return new self('too_many_files', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
