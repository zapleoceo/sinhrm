<?php

declare(strict_types=1);

namespace App\Modules\Perform\Repositories;

use App\Modules\Perform\Contracts\DevelopmentPlanRepository;
use App\Modules\Perform\Models\DevelopmentPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentDevelopmentPlanRepository implements DevelopmentPlanRepository
{
    public function list(?array $visibleIds, ?int $employeeId, int $limit): Collection
    {
        return DevelopmentPlan::query()->with('employee:id,full_name')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $visibleIds ?? []))
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('id')->limit($limit)->get();
    }

    public function find(int $id): ?DevelopmentPlan
    {
        return DevelopmentPlan::query()->with('employee:id,full_name')->find($id);
    }

    public function save(?DevelopmentPlan $plan, array $attributes): DevelopmentPlan
    {
        $plan ??= new DevelopmentPlan;
        $plan->fill($attributes)->save();

        return $plan->load('employee:id,full_name');
    }

    public function delete(DevelopmentPlan $plan): void
    {
        $plan->delete();
    }
}
