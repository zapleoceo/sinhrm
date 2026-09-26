<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vacancy */
final class VacancyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ref = static fn (?object $m): ?array => $m === null ? null : ['id' => $m->id, 'name' => $m->name];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'branch_id' => $this->branch_id,
            'branch' => $this->relationLoaded('branch') ? $ref($this->branch) : null,
            'department_id' => $this->department_id,
            'department' => $this->relationLoaded('department') ? $ref($this->department) : null,
            'position_id' => $this->position_id,
            'position' => $this->relationLoaded('position') ? $ref($this->position) : null,
            'recruiter_id' => $this->recruiter_id,
            'recruiter' => $this->relationLoaded('recruiter') ? $ref($this->recruiter) : null,
            'hiring_manager_id' => $this->hiring_manager_id,
            'hiring_manager' => $this->relationLoaded('hiringManager') ? $ref($this->hiringManager) : null,
            'pipeline_id' => $this->pipeline_id,
            'stages' => $this->relationLoaded('pipeline') ? StageResource::collection($this->pipeline->stages) : [],
            'description' => $this->description,
            'applications_count' => (int) ($this->applications_count ?? 0),
            'active_applications_count' => (int) ($this->active_applications_count ?? 0),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
