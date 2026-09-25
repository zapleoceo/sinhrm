<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\MigrationRunner;
use Illuminate\Contracts\Console\Kernel;

final class ArtisanMigrationRunner implements MigrationRunner
{
    public function __construct(private readonly Kernel $artisan) {}

    public function migrate(): string
    {
        $this->artisan->call('migrate', ['--force' => true]);

        return $this->artisan->output();
    }

    public function rebuildWithSeed(): string
    {
        $this->artisan->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        return $this->artisan->output();
    }
}
