<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Database\Seeders;

use App\Modules\Recruiting\Services\RecruitingDemoData;
use Illuminate\Database\Seeder;

/**
 * Synthetic Recruiting demo for preview/local DBs. A seeder (not an artisan call): migrate:fresh --seed runs inside
 * the HTTP request POST /api/ops/migrate?fresh=1, where console commands are not registered.
 */
final class RecruitingDemoSeeder extends Seeder
{
    public function run(RecruitingDemoData $demo): void
    {
        // Counts and duration are logged by the service (recruiting.demo_generated).
        $demo->generate();
    }
}
