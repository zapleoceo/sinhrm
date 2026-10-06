<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\UserTime;
use App\Modules\People\Contracts\EmployeeRepository;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "people.terminations" for POST /api/ops/jobs/run (idempotent):
 * - applies every scheduled termination whose day (fired_at) is over in the user's zone (UserTime, Europe/Kyiv):
 *   Kyiv date > fired_at, so access lasts until the end of that day; e.g. at 21:30 UTC in summer it is already the
 *   next day in Kyiv. Applying = TerminationService::applyDue (status, login block, EmployeeTerminated);
 * - re-sends EmployeeTerminated whose listener failed earlier (termination_event_pending).
 * Each employee is isolated: a failure (login block, database, listener) is logged with the id only, counted in
 * people_termination_failed and retried on the next run; the others go on.
 */
final readonly class ScheduledTerminationJob implements ScheduledJob
{
    public function __construct(
        private EmployeeRepository $employees,
        private TerminationService $terminations,
        private LoggerInterface $log,
    ) {}

    public function name(): string
    {
        return 'people.terminations';
    }

    public function run(Carbon $now): array
    {
        $applied = 0;
        $failed = 0;
        $blockSkipped = 0;
        $retried = 0;
        foreach ($this->employees->dueTerminations(UserTime::today($now)) as $id) {
            try {
                $outcome = $this->terminations->applyDue($id, $now);
                $applied += $outcome->applied ? 1 : 0;
                $blockSkipped += $outcome->loginBlockSkipped ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                $this->log->error('people.termination_failed', ['id' => $id, 'error' => $e::class]);
            }
        }
        foreach ($this->employees->pendingTerminationEvents() as $id) {
            try {
                $sent = $this->terminations->retryTerminatedEvent($id);
                $retried += $sent === true ? 1 : 0;
                $failed += $sent === false ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                $this->log->error('people.termination_event_retry_failed', ['id' => $id, 'error' => $e::class]);
            }
        }

        return [
            'people_terminated' => $applied,
            'people_termination_failed' => $failed,
            'people_login_block_skipped' => $blockSkipped,
            'people_termination_events_resent' => $retried,
        ];
    }
}
