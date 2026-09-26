<?php

declare(strict_types=1);

namespace App\Modules\Users\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation of the users admin; rendered as {message, code} with its HTTP status. */
final class UserAdminException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    public static function emailTaken(): self
    {
        return new self('email_taken', 409);
    }

    public static function selfChange(): self
    {
        return new self('self_change_forbidden', 422);
    }

    public static function lastSuperadmin(): self
    {
        return new self('last_superadmin', 422);
    }

    /** The Safe Speak handler flag is only for superadmin/admin. */
    public static function handlerRequiresAdmin(): self
    {
        return new self('handler_requires_admin', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
