<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/** The model answered, but not with the JSON the purpose expects. The message is a short reason, never the answer. */
final class InvalidAiOutput extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
