<?php

declare(strict_types=1);

namespace App\Modules\Observability\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** "errors.prune" for POST /api/ops/jobs/run: error groups not seen for 30 days are deleted. Idempotent. */
final class ErrorLogPruneJob implements ScheduledJob
{
    public const int RETENTION_DAYS = 30;

    public function name(): string
    {
        return 'errors.prune';
    }

    public function run(Carbon $now): array
    {
        $deleted = DB::table('error_events')->where('last_seen_at', '<', $now->copy()->subDays(self::RETENTION_DAYS))->delete();

        return ['errors_pruned' => $deleted];
    }
}
