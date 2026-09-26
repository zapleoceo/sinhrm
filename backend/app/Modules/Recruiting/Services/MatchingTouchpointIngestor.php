<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\IncomingMessage;
use App\Modules\Recruiting\Events\TouchpointRecorded;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Default ingestion: dedupe by (channel, external id) → match: explicit candidate, else the candidate already linked
 * to the same conversation (meta.thread), else by contact (phone / e-mail / Telegram) → attach to the given or the
 * candidate's latest active application; no match → unmatched (inbox). A concurrent duplicate delivery hits the
 * unique (channel, external_id) index and returns the stored touchpoint.
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

        $candidate = $this->matchCandidate($message);
        $meta = $message->meta;
        if ($message->contact !== null) {
            $meta['contact'] = $message->contact;
        }
        if ($message->thread !== null) {
            $meta['thread'] = $message->thread;
        }

        try {
            $touchpoint = $this->create($message, $candidate, $meta);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent delivery of the same (channel, external id) won the race: return the stored one.
            $existing = $message->externalId === null ? null : $this->touchpoints->findByExternalId($message->channel, $message->externalId);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
        if ($candidate !== null) {
            $this->events->dispatch(new TouchpointRecorded($touchpoint));
        }

        return $touchpoint;
    }

    /** Explicit target, else the candidate already linked to this conversation, else a candidate with the same contact. */
    private function matchCandidate(IncomingMessage $message): ?Candidate
    {
        $id = $message->candidateId;
        if ($id === null && $message->thread !== null) {
            $id = $this->touchpoints->candidateIdByThread($message->channel, $message->thread);
        }
        if ($id !== null) {
            $candidate = $this->candidates->find($id);
            if ($candidate !== null) {
                return $candidate;
            }
        }
        $keys = $this->normalizer->guess($message->contact);

        return $keys->isEmpty() ? null : $this->candidates->findByContacts($keys)[0] ?? null;
    }

    /** @param  array<string, mixed>  $meta */
    private function create(IncomingMessage $message, ?Candidate $candidate, array $meta): Touchpoint
    {
        return $this->touchpoints->create([
            'candidate_id' => $candidate?->id,
            'application_id' => $candidate === null ? null
                : ($message->applicationId ?? $this->applications->latestActiveFor($candidate->id)?->id),
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
    }
}
