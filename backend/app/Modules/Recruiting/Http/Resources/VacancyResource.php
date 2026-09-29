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
            // Open AND published on /jobs (Vacancy::scopeActive is the same rule for queries).
            'is_active' => $this->is_active,
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
            'published' => $this->published,
            'slug' => $this->slug,
            'public_description' => $this->public_description,
            'category_id' => $this->category_id,
            'category' => $this->relationLoaded('category') ? $ref($this->category) : null,
            'city_id' => $this->city_id,
            'city' => $this->relationLoaded('city') ? $ref($this->city) : null,
            'country' => $this->country,
            'employment_type' => $this->employment_type,
            'work_format' => $this->work_format,
            'experience_level' => $this->experience_level,
            'education_level' => $this->education_level,
            'salary_min' => $this->salary_min === null ? null : (float) $this->salary_min,
            'salary_max' => $this->salary_max === null ? null : (float) $this->salary_max,
            'salary_currency' => $this->salary_currency,
            'salary_visible' => $this->salary_visible,
            'languages' => $this->languages ?? [],
            'requirements' => $this->requirements,
            'responsibilities' => $this->responsibilities,
            'additional_info' => $this->additional_info,
            'external_postings' => $this->external_postings ?? [],
            'applications_count' => (int) ($this->applications_count ?? 0),
            'active_applications_count' => (int) ($this->active_applications_count ?? 0),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
