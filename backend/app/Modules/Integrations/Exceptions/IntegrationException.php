<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** Business-rule violation of the integrations admin; rendered as {message, code} with its HTTP status. */
final class IntegrationException extends BusinessRuleException
{
    public static function checkNotSupported(): self
    {
        return new self('check_not_supported', 422);
    }
}
