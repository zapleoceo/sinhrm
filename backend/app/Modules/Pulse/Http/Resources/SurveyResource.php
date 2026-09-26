<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Resources;

use App\Modules\Pulse\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Survey */
final class SurveyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type->value,
            'description' => $this->description,
            'questions' => $this->questions,
            'lifecycle_trigger' => $this->lifecycle_trigger?->value,
            'active' => $this->active,
            'waves_count' => (int) $this->waves_count,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
