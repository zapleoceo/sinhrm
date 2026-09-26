<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\DTO\CandidateFilter;
use App\Modules\Recruiting\DTO\CandidateMatch;
use App\Modules\Recruiting\DTO\ContactKeys;
use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/** Candidates: listing in scope, creation with dedupe by contacts, editing. */
final readonly class CandidateService
{
    public function __construct(
        private CandidateRepository $candidates,
        private ApplicationRepository $applications,
        private VacancyRepository $vacancies,
        private ApplicationService $applicationService,
        private RecruitingScope $scope,
        private ContactNormalizer $normalizer,
        private LoggerInterface $log,
        private AcquisitionChannelService $channels,
    ) {}

    /** @return LengthAwarePaginator<int, Candidate> */
    public function list(User $actor, CandidateFilter $filter): LengthAwarePaginator
    {
        return $this->candidates->paginate($this->scope->for($actor), $filter);
    }

    /** @throws ModelNotFoundException<Candidate> */
    public function find(int $id): Candidate
    {
        return $this->candidates->find($id) ?? throw (new ModelNotFoundException)->setModel(Candidate::class, [$id]);
    }

    /** @return Collection<int, Application> every application of the candidate with its route */
    public function applications(Candidate $candidate): Collection
    {
        return $this->applications->forCandidate($candidate->id);
    }

    /**
     * Creates a candidate (and, with vacancyId, their application on the first stage).
     * A match by normalized phone OR e-mail OR Telegram (global, all branches) → 409 duplicate_candidate; the
     * existing id is disclosed only when the actor may see that candidate. There is no "create anyway": contacts are
     * unique in the DB (partial unique indexes), so a concurrent insert also ends as the same 409.
     *
     * @throws RecruitingException
     */
    public function create(User $actor, CandidateData $data): Candidate
    {
        $keys = $this->keys($data);
        $this->assertNoDuplicate($actor, $keys, null);
        $vacancy = null;
        if ($data->vacancyId !== null) {
            $vacancy = $this->vacancies->find($data->vacancyId);
            if ($vacancy === null || ! $this->scope->canSeeVacancy($actor, $vacancy)) {
                throw RecruitingException::vacancyOutOfScope();
            }
        }

        try {
            return $this->insert($actor, $data, $keys, $vacancy);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent create: report it like the regular pre-check does.
            $this->assertNoDuplicate($actor, $keys, null);
            throw RecruitingException::duplicateCandidateRestricted();
        }
    }

    /**
     * For machine sources (Google Sheets import, job-board e-mails): no 409 — a candidate sharing a normalized contact
     * (global, all branches) is reused, otherwise a new one is created. With a vacancy the candidate is applied to it
     * (first stage, at $at) unless already applied. The actor may be null (background job); it becomes owner/creator.
     *
     * @throws RecruitingException no_contacts (nothing to dedupe by), full_name_required (new candidate without name)
     */
    public function createOrMatch(?User $actor, CandidateData $data, ?Vacancy $vacancy = null, ?Carbon $at = null): CandidateMatch
    {
        $keys = $this->keys($data);
        if ($keys->isEmpty()) {
            throw RecruitingException::noContacts();
        }
        $candidate = $this->candidates->findByContacts($keys)[0] ?? null;
        $created = false;
        if ($candidate === null) {
            if ($data->fullName === null) {
                throw RecruitingException::fullNameRequired();
            }
            $source = $data->source ?? CandidateSource::Import;
            $channelId = $this->channels->resolve($data->channelId, $source, $data->utm);
            try {
                $candidate = $this->candidates->create([
                    'full_name' => $data->fullName,
                    'phone' => $keys->phone,
                    'email' => $keys->email,
                    'telegram_username' => $keys->telegram,
                    'city_id' => $data->cityId,
                    'source' => $source->value,
                    'channel_id' => $channelId,
                    'added_via' => ($data->addedVia ?? AddedVia::Import)->value,
                    'utm' => $data->utm,
                    'tags' => $data->tags,
                    'owner_id' => $data->ownerId ?? $actor?->id,
                    'created_by' => $actor?->id,
                ]);
                $created = true;
                $this->log->info('recruiting.candidate_created', ['id' => $candidate->id, 'by' => $actor?->id, 'via' => 'match']);
            } catch (UniqueConstraintViolationException) {
                // A concurrent import created the same person: reuse it.
                $candidate = $this->candidates->findByContacts($keys)[0] ?? throw RecruitingException::noContacts();
            }
        }
        if ($vacancy === null) {
            return new CandidateMatch($candidate, $created);
        }
        $existing = $this->applications->findFor($candidate->id, $vacancy->id);
        if ($existing !== null) {
            return new CandidateMatch($candidate, $created, $existing);
        }

        return new CandidateMatch($candidate, $created, $this->applicationService->apply($actor, $candidate, $vacancy, $at), true);
    }

    private function insert(User $actor, CandidateData $data, ContactKeys $keys, ?Vacancy $vacancy): Candidate
    {
        $source = $data->source ?? CandidateSource::Manual;
        $channelId = $this->channels->resolve($data->channelId, $source, $data->utm);

        return $this->applications->transaction(function () use ($actor, $data, $keys, $vacancy, $source, $channelId): Candidate {
            $candidate = $this->candidates->create([
                'full_name' => (string) $data->fullName,
                'phone' => $keys->phone,
                'email' => $keys->email,
                'telegram_username' => $keys->telegram,
                'city_id' => $data->cityId,
                'source' => $source->value,
                'channel_id' => $channelId,
                'added_via' => ($data->addedVia ?? AddedVia::Manual)->value,
                'utm' => $data->utm,
                'tags' => $data->tags,
                'owner_id' => $data->ownerId ?? $actor->id,
                'created_by' => $actor->id,
            ]);
            if ($vacancy !== null) {
                $this->applicationService->apply($actor, $candidate, $vacancy);
            }
            $this->log->info('recruiting.candidate_created', ['id' => $candidate->id, 'by' => $actor->id]);

            return $candidate;
        });
    }

    /**
     * Partial update. A changed contact that belongs to another candidate → 409 (no force on edit).
     *
     * @throws RecruitingException
     */
    public function update(User $actor, Candidate $candidate, CandidateData $data): Candidate
    {
        $attributes = array_filter([
            'full_name' => $data->fullName,
            'city_id' => $data->cityId,
            'source' => $data->source?->value,
            'utm' => $data->utm,
            'tags' => $data->tags,
            'owner_id' => $data->ownerId,
            // An explicit channel only (a recruiter corrects it); editing UTM does not re-map the channel.
            'channel_id' => $data->channelId === null ? null : $this->channels->resolve($data->channelId, null, null),
        ], static fn (mixed $v): bool => $v !== null);
        $keys = $this->keys($data);
        $contactAttrs = array_filter([
            'phone' => $keys->phone,
            'email' => $keys->email,
            'telegram_username' => $keys->telegram,
        ], static fn (mixed $v): bool => $v !== null);
        if ($contactAttrs !== []) {
            $this->assertNoDuplicate($actor, $keys, $candidate->id);
        }
        $attributes += $contactAttrs;
        if ($attributes === []) {
            return $candidate;
        }
        try {
            $this->candidates->update($candidate, $attributes);
        } catch (UniqueConstraintViolationException) {
            $this->assertNoDuplicate($actor, $keys, $candidate->id);
            throw RecruitingException::duplicateCandidateRestricted();
        }
        $this->log->info('recruiting.candidate_updated', ['id' => $candidate->id, 'by' => $actor->id, 'fields' => array_keys($attributes)]);

        return $candidate;
    }

    /**
     * Global dedupe; a match outside the actor's scope is reported without id/field (no cross-branch enumeration).
     *
     * @throws RecruitingException
     */
    private function assertNoDuplicate(User $actor, ContactKeys $keys, ?int $exceptId): void
    {
        $match = $this->candidates->findByContacts($keys, $exceptId);
        if ($match === null) {
            return;
        }
        [$existing, $field] = $match;
        throw $this->scope->canSeeCandidate($actor, $existing)
            ? RecruitingException::duplicateCandidate($existing->id, $field)
            : RecruitingException::duplicateCandidateRestricted();
    }

    private function keys(CandidateData $data): ContactKeys
    {
        return $this->normalizer->keys($data->phone, $data->email, $data->telegram);
    }
}
