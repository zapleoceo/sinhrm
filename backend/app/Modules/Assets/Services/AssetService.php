<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Models\User;
use App\Modules\Assets\Contracts\AssetRepository;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Exceptions\AssetException;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetType;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Company assets. Inventory numbers are unique (case-insensitive check + a unique index for races); handing out and
 * taking back always write the history (asset_assignments) and move the status in one transaction on a locked row.
 */
final readonly class AssetService
{
    public const int LIMIT = 500;

    public function __construct(private AssetRepository $assets) {}

    /** @return Collection<int, AssetType> */
    public function types(): Collection
    {
        return $this->assets->types();
    }

    /** @param  array<string, mixed>  $attributes */
    public function saveType(?AssetType $type, array $attributes): AssetType
    {
        return $this->assets->saveType($type, $attributes);
    }

    public function findType(int $id): AssetType
    {
        return $this->assets->findType($id) ?? abort(404);
    }

    /**
     * @param  array{status?: string|null, type_id?: int|null, q?: string|null, employee_id?: int|null}  $filter
     * @return Collection<int, Asset>
     */
    public function list(array $filter): Collection
    {
        return $this->assets->list($filter, self::LIMIT);
    }

    public function find(int $id): Asset
    {
        return $this->assets->find($id) ?? abort(404);
    }

    /** @param  array<string, mixed>  $attributes */
    public function save(?Asset $asset, array $attributes): Asset
    {
        if (($attributes['status'] ?? null) === AssetStatus::Assigned->value) {
            throw AssetException::statusViaAssign();
        }
        if ($asset !== null && $asset->status === AssetStatus::Assigned && isset($attributes['status'])) {
            // Leaving "assigned" happens through return (it closes the history period).
            throw AssetException::statusViaAssign();
        }
        if (isset($attributes['inventory_number'])) {
            $attributes['inventory_number'] = trim((string) $attributes['inventory_number']);
            if ($this->assets->inventoryNumberTaken($attributes['inventory_number'], $asset?->id)) {
                throw AssetException::inventoryNumberTaken();
            }
        }
        try {
            $saved = $this->assets->save($asset, $attributes);
        } catch (UniqueConstraintViolationException) {
            throw AssetException::inventoryNumberTaken();
        }

        return $this->find($saved->id);
    }

    public function assign(User $actor, Asset $asset, Employee $employee, ?Carbon $on, ?string $condition): Asset
    {
        if ($employee->status === EmployeeStatus::Terminated) {
            throw AssetException::employeeTerminated();
        }
        $this->assets->transaction(function () use ($actor, $asset, $employee, $on, $condition): void {
            $locked = $this->assets->lock($asset->id);
            if ($locked->status !== AssetStatus::InStock) {
                throw AssetException::notInStock();
            }
            $this->assets->openAssignment([
                'asset_id' => $locked->id,
                'employee_id' => $employee->id,
                'assigned_at' => ($on ?? Carbon::today())->toDateString(),
                'condition_out' => $condition,
                'assigned_by' => $actor->id,
            ]);
            $this->assets->save($locked, ['status' => AssetStatus::Assigned->value, 'employee_id' => $employee->id]);
        });

        return $this->find($asset->id);
    }

    public function return(User $actor, Asset $asset, ?Carbon $on, ?string $condition, AssetStatus $status): Asset
    {
        $this->assets->transaction(function () use ($actor, $asset, $on, $condition, $status): void {
            $locked = $this->assets->lock($asset->id);
            $current = $this->assets->currentAssignment($locked->id);
            if ($locked->status !== AssetStatus::Assigned || ! $current instanceof AssetAssignment) {
                throw AssetException::notAssigned();
            }
            $returnedAt = $on ?? Carbon::today();
            if ($returnedAt->lt($current->assigned_at)) {
                throw AssetException::returnBeforeAssign();
            }
            $this->assets->closeAssignment($current, [
                'returned_at' => $returnedAt->toDateString(),
                'condition_in' => $condition,
                'returned_by' => $actor->id,
            ]);
            $this->assets->save($locked, ['status' => $status->value, 'employee_id' => null]);
        });

        return $this->find($asset->id);
    }

    /** @return Collection<int, AssetAssignment> */
    public function historyOf(Employee $employee): Collection
    {
        return $this->assets->historyOf($employee->id);
    }

    /** @return Collection<int, Asset> */
    public function heldBy(int $employeeId): Collection
    {
        return $this->assets->heldBy($employeeId);
    }
}
