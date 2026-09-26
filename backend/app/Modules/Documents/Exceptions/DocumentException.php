<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Documents; rendered as {message, code, ...extra} with its HTTP status. */
final class DocumentException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    /** @param  list<string>  $tokens */
    public static function unknownVariables(array $tokens): self
    {
        return new self('unknown_variables', 422, ['variables' => $tokens]);
    }

    public static function notEditable(): self
    {
        return new self('not_editable', 409);
    }

    /** Send without content and without a file. */
    public static function empty(): self
    {
        return new self('empty_document', 422);
    }

    /** The employee has no SinHRM login, so nobody can acknowledge the document. */
    public static function noLogin(): self
    {
        return new self('employee_has_no_login', 422);
    }

    /** Acknowledge / reject only a sent document. */
    public static function notSent(): self
    {
        return new self('not_sent', 409);
    }

    public static function alreadySigned(): self
    {
        return new self('already_signed', 409);
    }

    public static function invalidFile(): self
    {
        return new self('invalid_file', 422);
    }

    public static function fileTooLarge(): self
    {
        return new self('file_too_large', 422);
    }

    public static function templateArchived(): self
    {
        return new self('template_archived', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
