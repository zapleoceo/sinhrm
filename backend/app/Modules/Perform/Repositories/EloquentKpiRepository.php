<?php

declare(strict_types=1);

namespace App\Modules\Perform\Repositories;

use App\Modules\Perform\Contracts\KpiRepository;
use App\Modules\Perform\Models\Kpi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentKpiRepository implements KpiRepository
{
    public function list(?array $visibleIds, ?int $employeeId, ?string $period, int $limit): Collection
    {
        return Kpi::query()->with('employee:id,full_name')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $visibleIds ?? []))
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->when($period !== null, fn (Builder $q) => $q->where('period', $period))
            ->orderByDesc('period')->orderBy('metric')->orderBy('id')->limit($limit)->get();
    }

    public function find(int $id): ?Kpi
    {
        return Kpi::query()->with('employee:id,full_name')->find($id);
    }

    public function exists(int $employeeId, string $metric, string $period, ?int $exceptId = null): bool
    {
        return Kpi::query()->where(['employee_id' => $employeeId, 'metric' => $metric, 'period' => $period])
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))->exists();
    }

    public function save(?Kpi $kpi, array $attributes): Kpi
    {
        $kpi ??= new Kpi;
        $kpi->fill($attributes)->save();

        return $kpi->load('employee:id,full_name');
    }

    public function delete(Kpi $kpi): void
    {
        $kpi->delete();
    }
}
