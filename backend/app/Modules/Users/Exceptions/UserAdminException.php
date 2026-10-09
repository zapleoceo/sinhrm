<?php

declare(strict_types=1);

namespace App\Modules\Users\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** Business-rule violation of the users admin; rendered as {message, code} with its HTTP status. */
final class UserAdminException extends BusinessRuleException
{
    public static function emailTaken(): self
    {
        return new self('email_taken', 409);
    }

    public static function selfChange(): self
    {
        return new self('self_change_forbidden', 422);
    }

    /** Superadmin comes only from SUPERADMIN_EMAIL (bootstrap): the API may keep it on a user, never give it. */
    public static function superadminNotAssignable(): self
    {
        return new self('superadmin_not_assignable', 422);
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
}
