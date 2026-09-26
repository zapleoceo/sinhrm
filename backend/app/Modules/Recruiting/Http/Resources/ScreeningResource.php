<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\CandidateScreening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AI screening of an application. "advisory" is always true: the UI labels it "Оцінка ШІ, рішення за людиною".
 *
 * @mixin CandidateScreening
 */
final class ScreeningResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'vacancy' => $this->relationLoaded('vacancy') ? ['id' => $this->vacancy->id, 'title' => $this->vacancy->title] : ['id' => $this->vacancy_id, 'title' => null],
            'status' => $this->status,
            'trigger' => $this->trigger,
            'score' => $this->score,
            'verdict' => $this->verdict,
            'summary' => $this->summary,
            'strengths' => $this->strengths ?? [],
            'gaps' => $this->gaps ?? [],
            'questions' => $this->questions ?? [],
            'error' => $this->error,
            'prompt_version' => $this->prompt_version,
            'advisory' => true,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
