<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

interface MigrationRunner
{
    /** Apply pending migrations. Returns the console output. */
    public function migrate(): string;

    /** Drop everything, migrate and seed synthetic data. Returns the console output. */
    public function rebuildWithSeed(): string;
}
