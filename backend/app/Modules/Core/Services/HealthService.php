<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\HealthCheck;

final class HealthService
{
    /** @param iterable<HealthCheck> $checks */
    public function __construct(private readonly iterable $checks) {}

    /** @return array{ok: bool, checks: array<string, array{ok: bool, detail?: string}>} */
    public function report(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[$check->name()] = $check->check();
        }

        $ok = array_reduce($results, fn (bool $carry, array $r): bool => $carry && $r['ok'], true);

        return ['ok' => $ok, 'checks' => $results];
    }
}
