<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Resources;

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
            'title' => $this->title,
            'assignee_id' => $this->assignee_id,
            'candidate' => $this->relationLoaded('candidate') && $this->candidate !== null
                ? ['id' => $this->candidate->id, 'name' => $this->candidate->full_name]
                : null,
            'application_id' => $this->application_id,
            'vacancy' => $vacancy === null ? null : ['id' => $vacancy->id, 'title' => $vacancy->title],
            'template_key' => $this->template_key,
            'due_at' => $this->due_at->toIso8601String(),
            'done_at' => $this->done_at?->toIso8601String(),
            'is_overdue' => $this->done_at === null && $this->due_at->lt(now()->startOfDay()),
        ];
    }
}
