<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Resources;

use App\Modules\Scripts\Models\ScriptVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A version with its whole content (versions are small: tens of steps/templates).
 *
 * @mixin ScriptVersion
 */
final class ScriptVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'script_id' => $this->script_id,
            'version' => $this->version,
            'is_draft' => $this->isDraft(),
            'published_at' => $this->published_at?->toIso8601String(),
            'author' => $this->relationLoaded('author') && $this->author !== null ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'content' => $this->content()->toArray(),
        ];
    }
}
