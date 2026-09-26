<?php

declare(strict_types=1);

namespace App\Modules\Assets\Contracts;

use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetType;
use Illuminate\Database\Eloquent\Collection;

interface AssetRepository
{
    /** @return Collection<int, AssetType> */
    public function types(): Collection;

    public function findType(int $id): ?AssetType;

    /** @param  array<string, mixed>  $attributes */
    public function saveType(?AssetType $type, array $attributes): AssetType;

    /**
     * @param  array{status?: string|null, type_id?: int|null, q?: string|null, employee_id?: int|null}  $filter
     * @return Collection<int, Asset>
     */
    public function list(array $filter, int $limit): Collection;

    public function find(int $id): ?Asset;

    /** @param  array<string, mixed>  $attributes */
    public function save(?Asset $asset, array $attributes): Asset;

    public function inventoryNumberTaken(string $number, ?int $exceptId): bool;

    /** Locks the asset row (inside a transaction): assign/return must not interleave. */
    public function lock(int $id): Asset;

    /** @param  array<string, mixed>  $attributes */
    public function openAssignment(array $attributes): AssetAssignment;

    public function currentAssignment(int $assetId): ?AssetAssignment;

    /** @param  array<string, mixed>  $attributes */
    public function closeAssignment(AssetAssignment $assignment, array $attributes): void;

    /** @return Collection<int, AssetAssignment> the employee's history (current first), with assets */
    public function historyOf(int $employeeId): Collection;

    /** @return Collection<int, Asset> assets the employee holds now */
    public function heldBy(int $employeeId): Collection;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
