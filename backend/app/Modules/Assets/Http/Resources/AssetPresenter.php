<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Resources;

use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetType;

final class AssetPresenter
{
    /** @return array<string, mixed> */
    public static function asset(Asset $a, bool $withHistory = false): array
    {
        $out = [
            'id' => $a->id,
            'inventory_number' => $a->inventory_number,
            'serial' => $a->serial,
            'name' => $a->name,
            'type' => $a->type === null ? null : ['id' => $a->type->id, 'name' => $a->type->name],
            'status' => $a->status->value,
            'cost' => $a->cost,
            'purchased_at' => $a->purchased_at?->toDateString(),
            'notes' => $a->notes,
            'employee' => $a->employee === null ? null : ['id' => $a->employee->id, 'full_name' => $a->employee->full_name],
        ];
        if ($withHistory) {
            $out['history'] = $a->assignments->map(static fn (AssetAssignment $h): array => self::assignment($h))->values()->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function assignment(AssetAssignment $h, bool $withAsset = false): array
    {
        $out = [
            'id' => $h->id,
            'employee' => $h->relationLoaded('employee') ? ['id' => $h->employee->id, 'full_name' => $h->employee->full_name] : ['id' => $h->employee_id],
            'assigned_at' => $h->assigned_at->toDateString(),
            'returned_at' => $h->returned_at?->toDateString(),
            'condition_out' => $h->condition_out,
            'condition_in' => $h->condition_in,
        ];
        if ($withAsset) {
            $out['asset'] = [
                'id' => $h->asset->id,
                'inventory_number' => $h->asset->inventory_number,
                'name' => $h->asset->name,
                'serial' => $h->asset->serial,
                'type' => $h->asset->type?->name,
                'status' => $h->asset->status->value,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function type(AssetType $t): array
    {
        return ['id' => $t->id, 'name' => $t->name];
    }
}
