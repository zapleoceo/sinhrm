<?php

declare(strict_types=1);

namespace App\Modules\People\DTO;

/**
 * What one scheduled termination run did (TerminationService::applyDue → ScheduledTerminationJob counters).
 * loginBlockSkipped: a login is linked but this termination did not block it (already blocked, or the last active
 * superadmin — AccountBlocker returned null).
 */
final readonly class TerminationOutcome
{
    public function __construct(
        public bool $applied,
        public bool $loginBlockSkipped = false,
    ) {}

    public static function skipped(): self
    {
        return new self(false);
    }
}
