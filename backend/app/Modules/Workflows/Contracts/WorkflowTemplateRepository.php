<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Contracts;

use App\Modules\Workflows\Enums\WorkflowTrigger;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Collection;

interface WorkflowTemplateRepository
{
    /** @return Collection<int, WorkflowTemplate> by name, with steps and runs_count */
    public function list(): Collection;

    public function find(int $id): ?WorkflowTemplate;

    /** @return Collection<int, WorkflowTemplate> active templates with this trigger, with steps */
    public function activeByTrigger(WorkflowTrigger $trigger): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): WorkflowTemplate;

    /** @param  array<string, mixed>  $attributes */
    public function update(WorkflowTemplate $template, array $attributes): WorkflowTemplate;

    /**
     * Replaces the steps: rows with a known id are updated, new ones created, missing ones deleted; position = order.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public function syncSteps(WorkflowTemplate $template, array $steps): void;

    /** @param  list<int>  $stepIds  every step id of the template, in the new order */
    public function reorder(WorkflowTemplate $template, array $stepIds): void;

    public function hasRuns(WorkflowTemplate $template): bool;

    public function delete(WorkflowTemplate $template): void;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
