<?php

declare(strict_types=1);

namespace App\Modules\Perform\Repositories;

use App\Modules\Perform\Contracts\ObjectiveRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Enums\ObjectiveVisibility;
use App\Modules\Perform\Models\Objective;
use App\Modules\Perform\Models\ObjectiveCheckin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentObjectiveRepository implements ObjectiveRepository
{
    public function visible(PerformViewer $viewer, ?string $period, ?int $ownerId, int $limit): Collection
    {
        $ids = $viewer->visibleIds();

        return Objective::query()->with('owner:id,full_name')
            ->when($ids !== null, fn (Builder $q) => $q->where(function (Builder $w) use ($ids, $viewer): void {
                $w->where('visibility', ObjectiveVisibility::Public->value)->orWhereIn('owner_employee_id', $ids ?? []);
                if ($viewer->departmentId !== null) {
                    $w->orWhere(fn (Builder $t) => $t->where('visibility', ObjectiveVisibility::Team->value)
                        ->where('department_id', $viewer->departmentId));
                }
            }))
            ->when($period !== null, fn (Builder $q) => $q->where('period', $period))
            ->when($ownerId !== null, fn (Builder $q) => $q->where('owner_employee_id', $ownerId))
            ->orderBy('id')->limit($limit)->get();
    }

    public function find(int $id): ?Objective
    {
        return Objective::query()->with(['owner:id,full_name', 'checkins.author:id,name'])->find($id);
    }

    public function parentOf(int $id): ?int
    {
        $parent = Objective::query()->whereKey($id)->value('parent_objective_id');

        return is_numeric($parent) ? (int) $parent : null;
    }

    public function create(array $attributes): Objective
    {
        return Objective::query()->create($attributes);
    }

    public function update(Objective $objective, array $attributes): Objective
    {
        $objective->fill($attributes)->save();

        return $objective;
    }

    public function addCheckin(array $attributes): ObjectiveCheckin
    {
        return ObjectiveCheckin::query()->create($attributes);
    }

    public function delete(Objective $objective): void
    {
        $objective->delete();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
