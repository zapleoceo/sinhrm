<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Repositories;

use App\Modules\Workflows\Contracts\WorkflowRunRepository;
use App\Modules\Workflows\DTO\RunFilter;
use App\Modules\Workflows\Enums\RunStatus;
use App\Modules\Workflows\Enums\RunStepStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentWorkflowRunRepository implements WorkflowRunRepository
{
    private const array RELATIONS = ['employee', 'starter', 'steps.assignee', 'steps.completer'];

    public function list(RunFilter $filter, int $limit): Collection
    {
        return WorkflowRun::query()
            ->with(self::RELATIONS)
            ->when($filter->employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $filter->employeeIds ?? []))
            ->when($filter->employeeId, fn (Builder $q, int $id) => $q->where('employee_id', $id))
            ->when($filter->templateId, fn (Builder $q, int $id) => $q->where('template_id', $id))
            ->when($filter->status, fn (Builder $q, RunStatus $s) => $q->where('status', $s->value))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function find(int $id): ?WorkflowRun
    {
        return WorkflowRun::query()->with(self::RELATIONS)->find($id);
    }

    public function createRun(array $attributes): ?WorkflowRun
    {
        if (($attributes['trigger_key'] ?? null) === null) {
            return WorkflowRun::query()->create($attributes);
        }
        $now = Carbon::now();
        $row = $attributes + ['status' => RunStatus::Running->value, 'depth' => 0, 'created_at' => $now, 'updated_at' => $now];
        if (WorkflowRun::query()->insertOrIgnore([$row]) === 0) {
            return null;
        }

        return WorkflowRun::query()
            ->where('template_id', $attributes['template_id'])
            ->where('employee_id', $attributes['employee_id'])
            ->where('trigger_key', $attributes['trigger_key'])
            ->first();
    }

    public function createSteps(WorkflowRun $run, array $steps): void
    {
        foreach ($steps as $step) {
            WorkflowRunStep::query()->create(['run_id' => $run->id] + $step);
        }
    }

    public function findStep(int $id): ?WorkflowRunStep
    {
        return WorkflowRunStep::query()->with(['run.employee', 'assignee'])->find($id);
    }

    public function dueSteps(Carbon $now, int $limit): Collection
    {
        return WorkflowRunStep::query()
            ->with('run.employee')
            ->where('status', RunStepStatus::Pending->value)
            ->whereNull('executed_at')
            ->where('due_at', '<=', $now)
            ->whereHas('run', fn (Builder $q) => $q->where('status', RunStatus::Running->value))
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function claim(WorkflowRunStep $step, Carbon $now): bool
    {
        $claimed = WorkflowRunStep::query()
            ->whereKey($step->id)
            ->where('status', RunStepStatus::Pending->value)
            ->whereNull('executed_at')
            ->update(['executed_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]) === 1;
        if ($claimed) {
            $step->refresh();
        }

        return $claimed;
    }

    public function updateStep(WorkflowRunStep $step, array $attributes): WorkflowRunStep
    {
        $step->fill($attributes)->save();

        return $step;
    }

    public function finishOpenStep(WorkflowRunStep $step, array $attributes): bool
    {
        $row = $attributes;
        if (array_key_exists('result', $row)) {
            $row['result'] = $row['result'] === null ? null : json_encode($row['result']);
        }
        $updated = WorkflowRunStep::query()->whereKey($step->id)
            ->whereNotIn('status', [RunStepStatus::Done->value, RunStepStatus::Skipped->value])
            ->update($row + ['updated_at' => Carbon::now()]) === 1;
        if ($updated) {
            $step->refresh();
        }

        return $updated;
    }

    public function updateRun(WorkflowRun $run, array $attributes): WorkflowRun
    {
        $run->fill($attributes)->save();

        return $run;
    }

    public function unfinishedCount(WorkflowRun $run): int
    {
        return WorkflowRunStep::query()->where('run_id', $run->id)
            ->whereNotIn('status', [RunStepStatus::Done->value, RunStepStatus::Skipped->value])
            ->count();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
