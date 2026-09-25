<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\RejectReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RejectReason */
final class RejectReasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'active' => $this->active];
    }
}
