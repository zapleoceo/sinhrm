<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * One dependency the application needs to work (database, AI provider, …).
 * Modules register their checks; /api/health reports all of them.
 */
interface HealthCheck
{
    public function name(): string;

    /** @return array{ok: bool, detail?: string} */
    public function check(): array;
}
