<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Resources;

use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wave for admins (with the response count). Never the salt. The respondent view — WaveController::mine/form.
 *
 * @mixin SurveyWave
 */
final class WaveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey' => ['id' => $this->survey->id, 'title' => $this->survey->title, 'type' => $this->survey->type->value],
            'parent_wave_id' => $this->parent_wave_id,
            'schedule' => $this->schedule->value,
            'audience' => [
                'branch_ids' => $this->audience['branch_ids'] ?? [],
                'department_ids' => $this->audience['department_ids'] ?? [],
            ],
            'anonymous' => $this->anonymous,
            'min_group_size' => $this->min_group_size,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'status' => $this->status->value,
            'lifecycle' => $this->isLifecycle(),
            'subject_employee_id' => $this->subject_employee_id,
            'responses_count' => (int) $this->responses_count,
        ];
    }
}
