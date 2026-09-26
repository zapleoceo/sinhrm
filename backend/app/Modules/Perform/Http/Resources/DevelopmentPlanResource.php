<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Resources;

use App\Modules\Perform\Models\DevelopmentPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DevelopmentPlan */
final class DevelopmentPlanResource extends JsonResource
{
    private bool $canEdit = false;

    public static function for(DevelopmentPlan $plan, bool $canEdit): self
    {
        $resource = new self($plan);
        $resource->canEdit = $canEdit;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $done = count(array_filter($this->actions, static fn (array $a): bool => $a['done']));

        return [
            'id' => $this->id,
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'title' => $this->title,
            'goals' => $this->goals,
            'actions' => $this->actions,
            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status->value,
            'progress' => ['done' => $done, 'total' => count($this->actions)],
            'can_edit' => $this->canEdit,
        ];
    }
}
