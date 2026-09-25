<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PipelineStage */
final class StageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'position' => $this->position,
            'is_terminal' => $this->is_terminal,
            'is_reject' => $this->isReject(),
            'is_hire' => $this->isHire(),
        ];
    }
}
