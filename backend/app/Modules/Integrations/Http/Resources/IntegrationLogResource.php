<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IntegrationLog */
final class IntegrationLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level->value,
            'message' => $this->message,
            'context' => $this->context ?? (object) [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
