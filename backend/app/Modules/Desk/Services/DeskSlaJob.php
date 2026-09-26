<?php

declare(strict_types=1);

namespace App\Modules\Desk\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Desk\Contracts\DeskRepository;
use App\Modules\Desk\Support\Sla;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Support\Carbon;

/**
 * "desk.sla" for POST /api/ops/jobs/run: for every open case whose SLA target is breached, a task "SLA breached"
 * for the assignee (or the category's default assignee, or the first HR admin). Idempotent: one task per case and
 * target — the task key is "desk:<case id>:first_response|resolve" (unique per employee + key in the task list).
 */
final readonly class DeskSlaJob implements ScheduledJob
{
    public const string RULE_PREFIX = 'desk:';

    public function __construct(private DeskRepository $desk, private TaskService $tasks) {}

    public function name(): string
    {
        return 'desk.sla';
    }

    public function run(Carbon $now): array
    {
        $breaches = 0;
        $unassigned = 0;
        $fallback = null;
        foreach ($this->desk->openWithSla() as $case) {
            $sla = Sla::of($case->created_at, $case->category->first_response_hours, $case->category->resolve_hours, $case->first_response_at, $case->resolved_at, $now);
            $targets = array_filter([
                'first_response' => $sla['first_response_breached'] ? $sla['first_response_due'] : null,
                'resolve' => $sla['resolve_breached'] ? $sla['resolve_due'] : null,
            ]);
            if ($targets === []) {
                continue;
            }
            $assignee = $case->assignee_id ?? $case->category->default_assignee_id ?? ($fallback ??= $this->desk->fallbackHrUserId());
            if ($assignee === null) {
                $unassigned++;

                continue;
            }
            foreach (array_keys($targets) as $target) {
                $this->tasks->schedule(new NewTask(
                    assigneeId: $assignee,
                    type: TaskType::DeskSla,
                    title: sprintf('SLA порушено (%s): звернення #%d', $target === 'resolve' ? 'вирішення' : 'перша відповідь', $case->id),
                    dueAt: $now->copy(),
                    ruleKey: self::RULE_PREFIX.$case->id.':'.$target,
                    employeeId: $case->employee_id,
                    link: '/desk/cases/'.$case->id,
                ));
                $breaches++;
            }
        }

        return ['sla_breaches' => $breaches, 'sla_unassigned' => $unassigned];
    }
}
