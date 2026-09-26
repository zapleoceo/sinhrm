<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;

/** Registered for POST /api/ops/jobs/run (cron every 30 min): leave accrual and Jan 1 expiry. Idempotent. */
final readonly class AccrualJob implements ScheduledJob
{
    public function __construct(private AccrualService $accruals) {}

    public function name(): string
    {
        return 'timeoff.accrue';
    }

    public function run(Carbon $now): array
    {
        return $this->accruals->run($now);
    }
}
