<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** A personal-data request that can't run: unknown subject (404) or a module blocker such as not_terminated (409). */
final class PrivacyException extends BusinessRuleException
{
    public static function blocked(string $code): self
    {
        return new self($code, $code === 'not_found' ? 404 : 409);
    }
}
