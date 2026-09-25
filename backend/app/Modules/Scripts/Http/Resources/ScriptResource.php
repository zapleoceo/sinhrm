<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Resources;

use App\Modules\Scripts\Models\Script;
use App\Modules\Scripts\Models\ScriptVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List item: {id, name, channel, archived, active_version: {id, version, published_at}|null, draft: {…}|null}.
 * With withContent(): active_version and draft carry their full content (the editor).
 *
 * @mixin Script
 */
final class ScriptResource extends JsonResource
{
    private bool $withContent = false;

    public function withContent(): self
    {
        $this->withContent = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel->value,
            'archived' => $this->archived,
            'active_version' => $this->version($this->activeVersion, $request),
            'draft' => $this->version($this->draft, $request),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function version(?ScriptVersion $version, Request $request): ?array
    {
        if ($version === null) {
            return null;
        }
        if ($this->withContent) {
            return (new ScriptVersionResource($version))->toArray($request);
        }

        return [
            'id' => $version->id,
            'version' => $version->version,
            'published_at' => $version->published_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }
}
