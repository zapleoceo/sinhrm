<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Models\User;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Support\TouchpointRedaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A touch as the UI sees it. Sensitive touches (meta.kind = offer — the offer text carries the salary) are returned
 * without body and subject to anyone who may not see the offer itself (ApplicationPolicy::offer): the candidate card
 * is open to branch viewers and to assigned interviewers, the offer is not.
 *
 * @mixin Touchpoint
 */
final class TouchpointResource extends JsonResource
{
    /** Meta keys exposed to the UI (anything else an integration stores stays internal). */
    private const array PUBLIC_META = [
        'duration_sec', 'recording_url', 'contact', 'from_stage_id', 'to_stage_id',
        // e-mail (mail agent) and meetings (Google Calendar)
        'subject', 'from', 'parser', 'full_name', 'vacancy_title', 'cv_url',
        'event_id', 'meet_link', 'html_link', 'start', 'end', 'meeting_type', 'title',
        // channels (Channels module): demo = recorded in demo mode without a provider call
        'sender_name', 'call_status', 'edited', 'demo',
        // what kind of touch this is when the text is restricted (offer)
        'kind',
    ];

    /** Meta keys dropped together with the body when the touch is redacted. */
    private const array SECRET_META = ['subject', 'recording_url'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $redacted = $this->isRedactedFor($request);
        $meta = array_intersect_key($this->meta ?? [], array_flip(self::PUBLIC_META));
        if ($redacted) {
            $meta = array_diff_key($meta, array_flip(self::SECRET_META));
        }

        return [
            'id' => $this->id,
            'candidate_id' => $this->candidate_id,
            'application_id' => $this->application_id,
            'channel' => $this->channel->value,
            'direction' => $this->direction->value,
            'author' => $this->relationLoaded('author') && $this->author !== null ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'body' => $redacted ? null : $this->body,
            'meta' => (object) $meta,
            'via_product' => $this->via_product,
            'integration_key' => $this->integration_key,
            'redacted' => $redacted,
        ];
    }

    /** Offer touches: readable only for who passes ApplicationPolicy::offer on the touch's application. */
    private function isRedactedFor(Request $request): bool
    {
        $user = $request->user();
        assert($this->resource instanceof Touchpoint);

        return TouchpointRedaction::restricted($this->resource, $user instanceof User ? $user : null);
    }
}
