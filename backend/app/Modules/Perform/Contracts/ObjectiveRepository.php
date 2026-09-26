<?php

declare(strict_types=1);

namespace App\Modules\Perform\Contracts;

use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Models\Objective;
use App\Modules\Perform\Models\ObjectiveCheckin;
use Illuminate\Database\Eloquent\Collection;

interface ObjectiveRepository
{
    /**
     * Objectives the viewer may read (ObjectiveService::canView as SQL), with owner.
     *
     * @return Collection<int, Objective>
     */
    public function visible(PerformViewer $viewer, ?string $period, ?int $ownerId, int $limit): Collection;

    public function find(int $id): ?Objective;

    public function parentOf(int $id): ?int;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Objective;

    /** @param  array<string, mixed>  $attributes */
    public function update(Objective $objective, array $attributes): Objective;

    /** @param  array<string, mixed>  $attributes */
    public function addCheckin(array $attributes): ObjectiveCheckin;

    public function delete(Objective $objective): void;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
