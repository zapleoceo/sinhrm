<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Scripts\DTO\ApplicationActivity;
use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface TaskRepository
{
    /**
     * Tasks visible in the scope (restricted: assigned to the user or on an application of their branches),
     * soonest due first, at most $limit.
     *
     * @return Collection<int, Task> with candidate and application.vacancy
     */
    public function list(Scope $scope, TaskFilter $filter, Carbon $now, int $limit): Collection;

    public function find(int $id): ?Task;

    public function isVisible(Scope $scope, Task $task): bool;

    /** @param  array<string, mixed>  $attributes */
    public function update(Task $task, array $attributes): Task;

    /** Atomic done/undone: UPDATE ... WHERE done_at IS [NOT] NULL. False when already in that state. */
    public function markDone(Task $task, bool $done, Carbon $at): bool;

    /**
     * Inserts the follow-up unless a task with the same (application_id, rule_key) exists. Idempotent under races
     * (unique index).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFollowupOnce(array $attributes): bool;

    /**
     * Creates the employee task unless one with the same (employee_id, rule_key) exists; returns the stored one.
     *
     * @param  array<string, mixed>  $attributes  employee_id and rule_key are required
     */
    public function createOnce(array $attributes): Task;

    public function findByRule(int $employeeId, string $ruleKey): ?Task;

    /** @return list<ApplicationActivity> every active application with the moments the follow-up rules need */
    public function activities(): array;
}
