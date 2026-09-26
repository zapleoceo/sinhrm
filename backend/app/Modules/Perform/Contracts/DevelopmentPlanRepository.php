<?php

declare(strict_types=1);

namespace App\Modules\Perform\Contracts;

use App\Modules\Perform\Models\DevelopmentPlan;
use Illuminate\Database\Eloquent\Collection;

interface DevelopmentPlanRepository
{
    /**
     * @param  list<int>|null  $visibleIds  null = all
     * @return Collection<int, DevelopmentPlan>
     */
    public function list(?array $visibleIds, ?int $employeeId, int $limit): Collection;

    public function find(int $id): ?DevelopmentPlan;

    /** @param  array<string, mixed>  $attributes */
    public function save(?DevelopmentPlan $plan, array $attributes): DevelopmentPlan;

    public function delete(DevelopmentPlan $plan): void;
}
