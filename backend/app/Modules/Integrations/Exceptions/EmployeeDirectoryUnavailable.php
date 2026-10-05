<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use RuntimeException;

final class EmployeeDirectoryUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
