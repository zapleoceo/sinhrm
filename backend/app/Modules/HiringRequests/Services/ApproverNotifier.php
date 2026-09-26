<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Support\Carbon;

/**
 * Notifications are tasks in "Мої задачі" (source "hiring"):
 * - the current step's approver(s) get "Погодити заявку на підбір: <title>" when the step becomes active, due at the
 *   step's SLA; exactly once per step (the notified flag is compare-and-set; rule key hrq:<step>:<user>);
 * - when the step is decided, every open task of the step is closed;
 * - an overdue step escalates once to HR admins (rule key hrq-sla:<step>:<user>, flag escalated).
 * A role step notifies at most ROLE_LIMIT holders of the role. The task's employee is the requester's employee card
 * (if any) — only for context; "once" is guaranteed by the flags, not by the task table.
 */
final readonly class ApproverNotifier
{
    public const int ROLE_LIMIT = 20;

    public const string RULE = 'hrq:';

    public const string SLA_RULE = 'hrq-sla:';

    public function __construct(
        private HiringRequestRepository $requests,
        private TaskService $tasks,
        private EmployeeRepository $employees,
    ) {}

    /** @return list<int> users who can act on the step (never the requester) */
    public function assignees(HiringRequest $request, HiringApproval $step): array
    {
        $ids = $step->approver_id !== null
            ? [$step->approver_id]
            : ($step->role !== null ? $this->requests->usersWithRole($step->role, self::ROLE_LIMIT) : []);

        return array_values(array_filter($ids, static fn (int $id): bool => $id !== $request->requester_id));
    }

    public function notify(HiringRequest $request, HiringApproval $step, Carbon $now): int
    {
        if (! $this->requests->markNotified($step)) {
            return 0;
        }
        $created = 0;
        foreach ($this->assignees($request, $step) as $userId) {
            $this->tasks->schedule(new NewTask(
                assigneeId: $userId,
                type: TaskType::HiringApproval,
                title: 'Погодити заявку на підбір: '.$request->title,
                dueAt: $step->due_at ?? $now->copy(),
                ruleKey: self::RULE.$step->id.':'.$userId,
                employeeId: $this->requesterEmployeeId($request),
                link: '/hiring-requests/'.$request->id,
            ));
            $created++;
        }

        return $created;
    }

    public function closeStep(HiringApproval $step, Carbon $now): void
    {
        $this->tasks->closeByRulePrefix(self::RULE.$step->id.':', $now);
        $this->tasks->closeByRulePrefix(self::SLA_RULE.$step->id.':', $now);
    }

    /** Overdue step → one task per HR admin (once per step). */
    public function escalate(HiringRequest $request, HiringApproval $step, Carbon $now): int
    {
        if (! $this->requests->markEscalated($step)) {
            return 0;
        }
        $admins = array_values(array_unique([
            ...$this->requests->usersWithRole('admin', self::ROLE_LIMIT),
            ...$this->requests->usersWithRole('superadmin', self::ROLE_LIMIT),
        ]));
        foreach ($admins as $userId) {
            $this->tasks->schedule(new NewTask(
                assigneeId: $userId,
                type: TaskType::HiringApproval,
                title: sprintf('Прострочено погодження (%s): заявка «%s»', $step->name, $request->title),
                dueAt: $now->copy(),
                ruleKey: self::SLA_RULE.$step->id.':'.$userId,
                employeeId: $this->requesterEmployeeId($request),
                link: '/hiring-requests/'.$request->id,
            ));
        }

        return count($admins);
    }

    private function requesterEmployeeId(HiringRequest $request): ?int
    {
        return $request->requester_id === null ? null : $this->employees->findByUser($request->requester_id)?->id;
    }
}
