<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Resources;

use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
final class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $vacancy = $this->relationLoaded('application') ? $this->application?->vacancy : null;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'source' => $this->type->source()->value,
            'title' => $this->title,
            'link' => $this->link,
            'employee' => $this->relationLoaded('employee') && $this->employee !== null
                ? ['id' => $this->employee->id, 'name' => $this->employee->full_name]
                : null,
            'assignee_id' => $this->assignee_id,
            'candidate' => $this->relationLoaded('candidate') && $this->candidate !== null
                ? ['id' => $this->candidate->id, 'name' => $this->candidate->full_name]
                : null,
            'application_id' => $this->application_id,
            'vacancy' => $vacancy === null ? null : ['id' => $vacancy->id, 'title' => $vacancy->title],
            'template_key' => $this->template_key,
            'due_at' => $this->due_at->toIso8601String(),
            'done_at' => $this->done_at?->toIso8601String(),
            // Day-granular for follow-ups; the 1-hour "call the new applicant" task is overdue by the minute.
            'is_overdue' => $this->done_at === null && $this->due_at->lt(
                $this->type === TaskType::NewApplicant ? now() : now()->startOfDay(),
            ),
        ];
    }
}
