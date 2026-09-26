<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditEntry */
final class AuditEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'user' => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name],
            'changes' => $this->changes,
            'meta' => $this->meta,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
