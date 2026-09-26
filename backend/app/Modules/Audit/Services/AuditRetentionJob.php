<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Contracts\AuditLogRepository;
use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;

/**
 * "audit.retention": deletes audit rows older than RETENTION_DAYS in batches of BATCH rows, within a time budget
 * that fits a 60 s serverless call. What is left is picked up by the next cron run. Idempotent.
 */
final readonly class AuditRetentionJob implements ScheduledJob
{
    public const int RETENTION_DAYS = 365;

    public const int BATCH = 1000;

    public const float TIME_BUDGET_SECONDS = 20.0;

    /** @param  (\Closure(): float)|null  $clock  seconds, monotonic; injectable for tests */
    public function __construct(private AuditLogRepository $repository, private ?\Closure $clock = null) {}

    public function name(): string
    {
        return 'audit.retention';
    }

    public function run(Carbon $now): array
    {
        $clock = $this->clock ?? static fn (): float => hrtime(true) / 1e9;
        $before = $now->copy()->subDays(self::RETENTION_DAYS);
        $started = $clock();
        $deleted = 0;
        $batches = 0;
        do {
            $n = $this->repository->purgeOlderThan($before, self::BATCH);
            $deleted += $n;
            $batches++;
        } while ($n === self::BATCH && $clock() - $started < self::TIME_BUDGET_SECONDS);

        return ['deleted' => $deleted, 'batches' => $batches, 'complete' => $n < self::BATCH];
    }
}
