<?php

declare(strict_types=1);

namespace App\Modules\Integrations\DTO;

use App\Modules\Integrations\Enums\EmployeeDirectoryGatewayState;

final readonly class EmployeeDirectorySourceStatus
{
    /** @param list<string> $missingInputs */
    public function __construct(
        public EmployeeDirectoryGatewayState $status,
        public array $missingInputs,
        public ?string $namespace = null,
    ) {}
}
