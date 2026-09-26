<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentTemplate */
final class DocumentTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'body' => $this->body,
            'archived' => $this->archived,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
