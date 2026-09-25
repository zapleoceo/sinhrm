<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Touchpoint */
final class TouchpointResource extends JsonResource
{
    /** Meta keys exposed to the UI (anything else an integration stores stays internal). */
    private const array PUBLIC_META = [
        'duration_sec', 'recording_url', 'contact', 'from_stage_id', 'to_stage_id',
        // e-mail (mail agent) and meetings (Google Calendar)
        'subject', 'from', 'parser', 'full_name', 'vacancy_title', 'cv_url',
        'event_id', 'meet_link', 'html_link', 'start', 'end', 'meeting_type', 'title',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'candidate_id' => $this->candidate_id,
            'application_id' => $this->application_id,
            'channel' => $this->channel->value,
            'direction' => $this->direction->value,
            'author' => $this->relationLoaded('author') && $this->author !== null ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'body' => $this->body,
            'meta' => (object) array_intersect_key($this->meta ?? [], array_flip(self::PUBLIC_META)),
            'via_product' => $this->via_product,
            'integration_key' => $this->integration_key,
        ];
    }
}
