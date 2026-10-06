<?php

declare(strict_types=1);

namespace App\Modules\Integrations\DTO;

/** Canonical DTO for an SDK adapter after the upstream wire schema has been confirmed. */
final readonly class EmployeeDirectorySnapshot
{
    /** @param list<array{source_id: string, display_name: string, branch_key: string|null, position_key: string|null, status_code: string}> $profiles */
    public function __construct(
        public string $namespace,
        public bool $complete,
        public array $profiles,
    ) {}
}
