<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Enums\HiringRequestStatus;
use App\Modules\Recruiting\Enums\VacancyStatus;
use Illuminate\Support\Carbon;

/**
 * "hiring.sla" for POST /api/ops/jobs/run (idempotent):
 * - a pending step without its approver tasks gets them (retry after a failed notification);
 * - an overdue pending step (SLA days passed) escalates once to HR admins;
 * - an in-progress request whose vacancy is closed or whose headcount is hired becomes closed.
 */
final readonly class HiringSlaJob implements ScheduledJob
{
    public function __construct(
        private HiringRequestRepository $requests,
        private ApproverNotifier $notifier,
        private HiringRequestService $service,
    ) {}

    public function name(): string
    {
        return 'hiring.sla';
    }

    public function run(Carbon $now): array
    {
        $notified = 0;
        $escalated = 0;
        foreach ($this->requests->pendingApprovals() as $step) {
            if (! $step->notified) {
                $notified += $this->notifier->notify($step->request, $step, $now);
            }
            if ($step->isOverdue($now) && ! $step->escalated) {
                $escalated += $this->notifier->escalate($step->request, $step, $now) > 0 ? 1 : 0;
            }
        }

        $closed = 0;
        $open = $this->requests->inProgress();
        $progress = $this->service->progress($open);
        foreach ($open as $request) {
            $p = $progress[$request->id];
            $vacancyClosed = $request->vacancy?->status === VacancyStatus::Closed;
            if (($vacancyClosed || $p['hired'] >= $request->headcount)
                && $this->requests->transition($request, HiringRequestStatus::InProgress, ['status' => HiringRequestStatus::Closed->value, 'closed_at' => $now])) {
                $closed++;
            }
        }

        return ['hiring_notified' => $notified, 'hiring_escalated' => $escalated, 'hiring_closed' => $closed];
    }
}
