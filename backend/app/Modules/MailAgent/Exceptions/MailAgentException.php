<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business error of the mail agent; rendered as {message, code} with its HTTP status. */
final class MailAgentException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    public static function duplicateRule(): self
    {
        return new self('duplicate_rule', 409);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
