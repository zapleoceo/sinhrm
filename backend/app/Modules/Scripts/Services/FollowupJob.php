<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;

/** Registered for POST /api/ops/jobs/run (cron every 30 min): creates due follow-up tasks. */
final readonly class FollowupJob implements ScheduledJob
{
    public function __construct(private FollowupService $followups) {}

    public function name(): string
    {
        return 'followups';
    }

    public function run(Carbon $now): array
    {
        return $this->followups->run($now);
    }
}
