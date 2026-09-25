<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\DTO\TimelineEntry;
use App\Modules\Recruiting\Models\StageChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {type: "touchpoint", at, touchpoint: {...}} or {type: "stage_change", at, stage_change: {...}}.
 *
 * @property TimelineEntry $resource
 */
final class TimelineEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;
        $item = $entry->item;
        $data = ['type' => $entry->type->value, 'at' => $entry->at->toIso8601String()];
        if ($item instanceof StageChange) {
            $ref = static fn (?object $m): ?array => $m === null ? null : ['id' => $m->id, 'name' => $m->name];
            $data['stage_change'] = [
                'id' => $item->id,
                'application_id' => $item->application_id,
                'vacancy' => ['id' => $item->application->vacancy->id, 'title' => $item->application->vacancy->title],
                'from_stage' => $ref($item->fromStage),
                'to_stage' => ['id' => $item->toStage->id, 'name' => $item->toStage->name, 'kind' => $item->toStage->kind->value],
                'by' => $ref($item->byUser),
                'reason' => $item->reason,
            ];
        } else {
            $data['touchpoint'] = (new TouchpointResource($item))->toArray($request);
        }

        return $data;
    }
}
