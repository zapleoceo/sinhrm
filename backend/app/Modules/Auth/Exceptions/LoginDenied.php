<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Auth\Enums\LoginDenial;
use RuntimeException;

final class LoginDenied extends RuntimeException
{
    public function __construct(public readonly LoginDenial $reason)
    {
        parent::__construct($reason->value);
    }
}
