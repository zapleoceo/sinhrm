<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** Business error of the mail agent; rendered as {message, code} with its HTTP status. */
final class MailAgentException extends BusinessRuleException
{
    public static function duplicateRule(): self
    {
        return new self('duplicate_rule', 409);
    }
}
