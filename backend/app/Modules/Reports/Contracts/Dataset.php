<?php

declare(strict_types=1);

namespace App\Modules\Reports\Contracts;

use App\Modules\Reports\DTO\ScopedContext;

/**
 * A dataset of the custom report builder: a WHITELIST of columns. The user only ever sends column keys; every key
 * maps to a fixed SQL expression defined here (never built from input). The scoped base query lives in the
 * repository (BuilderRepository::base).
 */
interface Dataset
{
    public const string STRING = 'string';

    public const string NUMBER = 'number';

    public const string DATE = 'date';

    public function key(): string;

    public function available(ScopedContext $ctx): bool;

    /**
     * @return array<string, array{expr: string, type: string, pii?: bool}> column key → fixed SQL expression, type,
     *                                                                      PII flag (admins only)
     */
    public function columns(): array;
}
