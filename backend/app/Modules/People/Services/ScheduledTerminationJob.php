<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\UserTime;
use App\Modules\People\Contracts\EmployeeRepository;
use Illuminate\Support\Carbon;

/**
 * "people.terminations" for POST /api/ops/jobs/run (idempotent): applies every scheduled termination whose date
 * (fired_at) has come in the user's zone (UserTime, Europe/Kyiv), e.g. at 21:30 UTC in summer it is already the
 * next day in Kyiv. Applying = TerminationService::applyDue (status, login block, EmployeeTerminated).
 */
final readonly class ScheduledTerminationJob implements ScheduledJob
{
    public function __construct(
        private EmployeeRepository $employees,
        private TerminationService $terminations,
    ) {}

    public function name(): string
    {
        return 'people.terminations';
    }

    public function run(Carbon $now): array
    {
        $applied = 0;
        foreach ($this->employees->dueTerminations(UserTime::today($now)) as $id) {
            $applied += $this->terminations->applyDue($id, $now) ? 1 : 0;
        }

        return ['people_terminated' => $applied];
    }
}
