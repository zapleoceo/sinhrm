<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Http\Resources;

use App\Modules\MailAgent\Models\MailMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailMessage */
final class MailMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'received_at' => $this->received_at->toIso8601String(),
            'sender' => $this->sender,
            'subject' => $this->subject,
            'kind' => $this->kind,
            'parser' => $this->parser,
            'outcome' => $this->outcome->value,
            'error' => $this->error,
            'candidate_id' => $this->candidate_id,
            'touchpoint_id' => $this->touchpoint_id,
        ];
    }
}
