<?php

declare(strict_types=1);

namespace App\Modules\Integrations\DTO;

use App\Modules\Integrations\Enums\IntegrationStatus;

/** Outcome of a connection check. $message must never contain secret values (the service scrubs it anyway). */
final readonly class CheckResult
{
    public function __construct(public IntegrationStatus $status, public ?string $message = null) {}

    public static function connected(): self
    {
        return new self(IntegrationStatus::Connected);
    }

    public static function error(string $message): self
    {
        return new self(IntegrationStatus::Error, $message);
    }

    /** Config looks valid but nothing was verified against the remote service. */
    public static function notVerified(string $message): self
    {
        return new self(IntegrationStatus::Demo, $message);
    }
}
