<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Resources;

use App\Modules\Perform\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Feedback */
final class FeedbackResource extends JsonResource
{
    private bool $canAnswer = false;

    public static function for(Feedback $feedback, bool $canAnswer): self
    {
        $resource = new self($feedback);
        $resource->canAnswer = $canAnswer;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from' => ['id' => $this->from->id, 'full_name' => $this->from->full_name],
            'to' => ['id' => $this->to->id, 'full_name' => $this->to->full_name],
            'type' => $this->type->value,
            'text' => $this->text,
            'visibility' => $this->visibility->value,
            'request_id' => $this->request_id,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_answer' => $this->canAnswer,
        ];
    }
}
