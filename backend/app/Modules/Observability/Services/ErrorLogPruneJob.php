<?php

declare(strict_types=1);

namespace App\Modules\Observability\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Observability\Contracts\ErrorEventRepository;
use Illuminate\Support\Carbon;

/** "errors.prune" for POST /api/ops/jobs/run: error groups not seen for 30 days are deleted. Idempotent. */
final class ErrorLogPruneJob implements ScheduledJob
{
    public const int RETENTION_DAYS = 30;

    public function __construct(private readonly ErrorEventRepository $events) {}

    public function name(): string
    {
        return 'errors.prune';
    }

    public function run(Carbon $now): array
    {
        $deleted = $this->events->pruneNotSeenSince($now->copy()->subDays(self::RETENTION_DAYS));

        return ['errors_pruned' => $deleted];
    }
}
