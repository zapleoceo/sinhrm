<?php

declare(strict_types=1);

namespace App\Modules\Assets\Repositories;

use App\Modules\Assets\Contracts\AssetRepository;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentAssetRepository implements AssetRepository
{
    public function types(): Collection
    {
        return AssetType::query()->orderBy('name')->get();
    }

    public function findType(int $id): ?AssetType
    {
        return AssetType::query()->find($id);
    }

    public function saveType(?AssetType $type, array $attributes): AssetType
    {
        $type ??= new AssetType;
        $type->fill($attributes)->save();

        return $type;
    }

    public function list(array $filter, int $limit): Collection
    {
        $q = isset($filter['q']) ? mb_strtolower(trim((string) $filter['q'])) : '';

        return Asset::query()
            ->with(['type', 'employee:id,full_name'])
            ->when(isset($filter['status']), static fn (Builder $b) => $b->where('status', $filter['status']))
            ->when(isset($filter['type_id']), static fn (Builder $b) => $b->where('type_id', $filter['type_id']))
            ->when(isset($filter['employee_id']), static fn (Builder $b) => $b->where('employee_id', $filter['employee_id']))
            ->when($q !== '', static function (Builder $b) use ($q): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $b->where(static fn (Builder $w) => $w
                    ->whereRaw("lower(inventory_number) like ? escape '!'", [$pattern])
                    ->orWhereRaw("lower(name) like ? escape '!'", [$pattern])
                    ->orWhereRaw("lower(coalesce(serial, '')) like ? escape '!'", [$pattern]));
            })
            ->orderBy('inventory_number')
            ->limit($limit)
            ->get();
    }

    public function find(int $id): ?Asset
    {
        return Asset::query()->with(['type', 'employee:id,full_name', 'assignments.employee:id,full_name'])->find($id);
    }

    public function save(?Asset $asset, array $attributes): Asset
    {
        $asset ??= new Asset;
        $asset->fill($attributes)->save();

        return $asset;
    }

    public function inventoryNumberTaken(string $number, ?int $exceptId): bool
    {
        return Asset::query()
            ->whereRaw('lower(inventory_number) = ?', [mb_strtolower($number)])
            ->when($exceptId !== null, static fn (Builder $b) => $b->whereKeyNot($exceptId))
            ->exists();
    }

    public function lock(int $id): Asset
    {
        return Asset::query()->lockForUpdate()->findOrFail($id);
    }

    public function openAssignment(array $attributes): AssetAssignment
    {
        return AssetAssignment::query()->create($attributes);
    }

    public function currentAssignment(int $assetId): ?AssetAssignment
    {
        return AssetAssignment::query()->where('asset_id', $assetId)->whereNull('returned_at')->latest('id')->first();
    }

    public function closeAssignment(AssetAssignment $assignment, array $attributes): void
    {
        $assignment->fill($attributes)->save();
    }

    public function historyOf(int $employeeId): Collection
    {
        return AssetAssignment::query()
            ->with('asset.type')
            ->where('employee_id', $employeeId)
            ->orderByRaw('case when returned_at is null then 0 else 1 end')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();
    }

    public function heldBy(int $employeeId): Collection
    {
        return Asset::query()->with('type')
            ->where('employee_id', $employeeId)
            ->where('status', AssetStatus::Assigned->value)
            ->orderBy('inventory_number')
            ->get();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(static fn (): mixed => $callback());
    }
}
