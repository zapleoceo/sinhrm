<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Http\Resources;

use App\Modules\MailAgent\Models\SenderRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SenderRule */
final class SenderRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pattern' => $this->pattern,
            'kind' => $this->kind->value,
            'parser' => $this->parser?->value,
            'hits' => $this->hits,
            // manual | ai: rules created by an auto-applied AI classification carry the confidence and the prompt version.
            'source' => $this->source,
            'ai_confidence' => $this->ai_confidence,
            'prompt_version' => $this->prompt_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
