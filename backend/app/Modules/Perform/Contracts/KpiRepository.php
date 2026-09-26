<?php

declare(strict_types=1);

namespace App\Modules\Perform\Contracts;

use App\Modules\Perform\Models\Kpi;
use Illuminate\Database\Eloquent\Collection;

interface KpiRepository
{
    /**
     * @param  list<int>|null  $visibleIds  null = all
     * @return Collection<int, Kpi>
     */
    public function list(?array $visibleIds, ?int $employeeId, ?string $period, int $limit): Collection;

    public function find(int $id): ?Kpi;

    public function exists(int $employeeId, string $metric, string $period, ?int $exceptId = null): bool;

    /** @param  array<string, mixed>  $attributes */
    public function save(?Kpi $kpi, array $attributes): Kpi;

    public function delete(Kpi $kpi): void;
}
