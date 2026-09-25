<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Support\Carbon;

/**
 * A background job run by the cron workflow through POST /api/ops/jobs/run (every ~30 min).
 * Must be idempotent: the same run twice (retries, overlapping cron) must not duplicate work.
 * Modules register jobs with $this->app->tag([...], ScheduledJob::class).
 */
interface ScheduledJob
{
    public function name(): string;

    /** @return array<string, int|string|bool> counters for the log/answer (never personal data) */
    public function run(Carbon $now): array;
}
