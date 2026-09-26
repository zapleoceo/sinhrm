<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Resources;

use App\Modules\Perform\Models\Kpi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A KPI with its attainment (actual / target, %, null until an actual is set or when target = 0).
 *
 * @mixin Kpi
 */
final class KpiResource extends JsonResource
{
    private bool $canEdit = false;

    public static function for(Kpi $kpi, bool $canEdit): self
    {
        $resource = new self($kpi);
        $resource->canEdit = $canEdit;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $target = (float) $this->target;
        $actual = $this->actual === null ? null : (float) $this->actual;

        return [
            'id' => $this->id,
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'metric' => $this->metric,
            'unit' => $this->unit,
            'period' => $this->period,
            'target' => $target,
            'actual' => $actual,
            'attainment' => $actual === null || $target == 0.0 ? null : (int) round($actual / $target * 100),
            'can_edit' => $this->canEdit,
        ];
    }
}
