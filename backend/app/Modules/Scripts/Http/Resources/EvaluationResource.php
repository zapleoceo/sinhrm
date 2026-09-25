<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Resources;

use App\Modules\Scripts\Models\ScriptEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full evaluation of a touch: score, engine, script version, steps with quotes, next step, recommendations.
 *
 * @mixin ScriptEvaluation
 */
final class EvaluationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $version = $this->relationLoaded('version') ? $this->version : null;

        return [
            'id' => $this->id,
            'touchpoint_id' => $this->touchpoint_id,
            'engine' => $this->engine->value,
            'score' => $this->score,
            'script' => $version === null ? null : [
                'id' => $version->script_id,
                'name' => $version->relationLoaded('script') ? $version->script->name : null,
                'version' => $version->version,
                'version_id' => $version->id,
            ],
            'steps' => $this->result['steps'] ?? [],
            'next_step' => $this->result['next_step'] ?? ['fixed' => false, 'quote' => null, 'negative_quote' => null],
            'objections' => $this->result['objections'] ?? [],
            'recommendations' => $this->result['recommendations'] ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
