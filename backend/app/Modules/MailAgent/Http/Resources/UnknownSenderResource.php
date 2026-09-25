<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Http\Resources;

use App\Modules\MailAgent\Models\UnknownSender;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnknownSender */
final class UnknownSenderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'sample_subject' => $this->sample_subject,
            'count' => $this->count,
            'first_seen_at' => $this->first_seen_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'suggested_kind' => $this->suggested_kind?->value,
            'suggested_parser' => $this->suggested_parser?->value,
        ];
    }
}
