<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Candidate */
final class CandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'telegram_username' => $this->telegram_username,
            'city_id' => $this->city_id,
            'city' => $this->relationLoaded('city') && $this->city !== null ? ['id' => $this->city->id, 'name' => $this->city->name] : null,
            'source' => $this->source->value,
            'utm' => $this->utm ?? (object) [],
            'tags' => $this->tags ?? [],
            'owner_id' => $this->owner_id,
            'owner' => $this->relationLoaded('owner') && $this->owner !== null ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null,
            'applications' => ApplicationResource::collection($this->whenLoaded('applications')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
