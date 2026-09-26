<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Repositories;

use App\Modules\Workflows\Contracts\WorkflowTemplateRepository;
use App\Modules\Workflows\Enums\WorkflowTrigger;
use App\Modules\Workflows\Models\WorkflowStep;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentWorkflowTemplateRepository implements WorkflowTemplateRepository
{
    public function list(): Collection
    {
        return WorkflowTemplate::query()->with('steps')->withCount('runs')->orderBy('name')->orderBy('id')->get();
    }

    public function find(int $id): ?WorkflowTemplate
    {
        return WorkflowTemplate::query()->with('steps')->withCount('runs')->find($id);
    }

    public function activeByTrigger(WorkflowTrigger $trigger): Collection
    {
        return WorkflowTemplate::query()->with('steps')
            ->where('trigger', $trigger->value)->where('active', true)->orderBy('id')->get();
    }

    public function create(array $attributes): WorkflowTemplate
    {
        return WorkflowTemplate::query()->create($attributes);
    }

    public function update(WorkflowTemplate $template, array $attributes): WorkflowTemplate
    {
        $template->fill($attributes)->save();

        return $template;
    }

    public function syncSteps(WorkflowTemplate $template, array $steps): void
    {
        $existing = WorkflowStep::query()->where('template_id', $template->id)->get()->keyBy('id');
        $kept = [];
        foreach ($steps as $position => $data) {
            $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : null;
            unset($data['id']);
            $attributes = ['position' => $position, 'template_id' => $template->id] + $data;
            $step = $id !== null ? $existing->get($id) : null;
            if ($step instanceof WorkflowStep) {
                $step->fill($attributes)->save();
                $kept[] = $step->id;
            } else {
                $kept[] = WorkflowStep::query()->create($attributes)->id;
            }
        }
        WorkflowStep::query()->where('template_id', $template->id)->whereNotIn('id', $kept)->delete();
    }

    public function reorder(WorkflowTemplate $template, array $stepIds): void
    {
        foreach ($stepIds as $position => $id) {
            WorkflowStep::query()->where('template_id', $template->id)->whereKey($id)->update(['position' => $position]);
        }
    }

    public function hasRuns(WorkflowTemplate $template): bool
    {
        return $template->runs()->exists();
    }

    public function delete(WorkflowTemplate $template): void
    {
        $template->delete();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
