<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\IncomingMessage;
use App\Modules\Recruiting\Events\TouchpointRecorded;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Default ingestion: dedupe by (channel, external id) → match the contact to a candidate (phone / e-mail /
 * Telegram) → attach to the candidate's latest active application; no match → unmatched (inbox).
 */
final readonly class MatchingTouchpointIngestor implements TouchpointIngestor
{
    public function __construct(
        private TouchpointRepository $touchpoints,
        private CandidateRepository $candidates,
        private ApplicationRepository $applications,
        private ContactNormalizer $normalizer,
        private Dispatcher $events,
    ) {}

    public function ingest(IncomingMessage $message): Touchpoint
    {
        if ($message->externalId !== null) {
            $existing = $this->touchpoints->findByExternalId($message->channel, $message->externalId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $keys = $this->normalizer->guess($message->contact);
        $candidate = $keys->isEmpty() ? null : $this->candidates->findByContacts($keys)[0] ?? null;
        $meta = $message->meta;
        if ($message->contact !== null) {
            $meta['contact'] = $message->contact;
        }

        $touchpoint = $this->touchpoints->create([
            'candidate_id' => $candidate?->id,
            'application_id' => $candidate !== null ? $this->applications->latestActiveFor($candidate->id)?->id : null,
            'branch_id' => $message->branchId,
            'channel' => $message->channel->value,
            'direction' => $message->direction->value,
            'author_id' => $message->authorId,
            'occurred_at' => $message->occurredAt,
            'body' => $message->body,
            'meta' => $meta === [] ? null : $meta,
            'external_id' => $message->externalId,
            'via_product' => $message->viaProduct,
            'integration_key' => $message->integrationKey,
        ]);
        if ($candidate !== null) {
            $this->events->dispatch(new TouchpointRecorded($touchpoint));
        }

        return $touchpoint;
    }
}
