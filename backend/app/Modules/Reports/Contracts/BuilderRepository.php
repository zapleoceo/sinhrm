<?php

declare(strict_types=1);

namespace App\Modules\Reports\Contracts;

use App\Modules\Reports\DTO\BuilderSpec;
use App\Modules\Reports\DTO\ScopedContext;

interface BuilderRepository
{
    /**
     * Runs a whitelisted spec on the scoped base query of its dataset.
     *
     * @param  array<string, array{expr: string, type: string, pii?: bool}>  $columns  the dataset whitelist
     * @return list<array<string, scalar|null>>
     */
    public function run(BuilderSpec $spec, array $columns, ScopedContext $ctx, int $limit): array;
}
