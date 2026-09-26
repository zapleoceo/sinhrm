<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Http\Resources;

use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Models\HiringRequest;
use Illuminate\Support\Carbon;

/** Hiring request as JSON: fields, route timeline with SLA flags, linked vacancy progress, the user's action flags. */
final class HiringRequestPresenter
{
    /**
     * @param  array{vacancy_status: string|null, hired: int, headcount: int, percent: int}|null  $progress
     * @param  array{edit: bool, submit: bool, decide: bool, cancel: bool, manage: bool}  $can
     * @return array<string, mixed>
     */
    public static function present(HiringRequest $r, ?array $progress, array $can, Carbon $now): array
    {
        $current = $r->currentApproval();

        return [
            'id' => $r->id,
            'title' => $r->title,
            'status' => $r->status->value,
            'priority' => $r->priority->value,
            'reason' => $r->reason->value,
            'headcount' => $r->headcount,
            'branch' => ['id' => $r->branch->id, 'name' => $r->branch->name],
            'department' => $r->department === null ? null : ['id' => $r->department->id, 'name' => $r->department->name],
            'position' => $r->position === null ? null : ['id' => $r->position->id, 'name' => $r->position->name],
            'replaced_employee' => $r->replacedEmployee === null ? null : ['id' => $r->replacedEmployee->id, 'full_name' => $r->replacedEmployee->full_name],
            'desired_start_date' => $r->desired_start_date?->toDateString(),
            'salary_min' => $r->salary_min === null ? null : (float) $r->salary_min,
            'salary_max' => $r->salary_max === null ? null : (float) $r->salary_max,
            'currency' => $r->currency,
            'requirements' => $r->requirements,
            'extra' => $r->extra ?? (object) [],
            'requester' => $r->requester === null ? null : ['id' => $r->requester->id, 'name' => $r->requester->name],
            'recruiter' => $r->recruiter === null ? null : ['id' => $r->recruiter->id, 'name' => $r->recruiter->name],
            'vacancy' => $r->vacancy === null ? null : ['id' => $r->vacancy->id, 'title' => $r->vacancy->title, 'status' => $r->vacancy->status->value],
            'progress' => $progress,
            'current_step' => $current === null ? null : ['position' => $current->position, 'name' => $current->name, 'due_at' => $current->due_at?->toIso8601String(), 'overdue' => $current->isOverdue($now)],
            'overdue' => $current?->isOverdue($now) ?? false,
            'approvals' => $r->approvals->map(static fn (HiringApproval $a): array => [
                'id' => $a->id,
                'position' => $a->position,
                'name' => $a->name,
                'kind' => $a->kind->value,
                'role' => $a->role,
                'approver' => $a->approver === null ? null : ['id' => $a->approver->id, 'name' => $a->approver->name],
                'status' => $a->status->value,
                'sla_days' => $a->sla_days,
                'activated_at' => $a->activated_at?->toIso8601String(),
                'due_at' => $a->due_at?->toIso8601String(),
                'overdue' => $a->isOverdue($now),
                'decided_by' => $a->decider === null ? null : ['id' => $a->decider->id, 'name' => $a->decider->name],
                'decided_at' => $a->decided_at?->toIso8601String(),
                'comment' => $a->comment,
            ])->values()->all(),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'closed_at' => $r->closed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'can' => $can,
        ];
    }
}
