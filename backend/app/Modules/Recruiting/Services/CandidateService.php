<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\DTO\CandidateFilter;
use App\Modules\Recruiting\DTO\ContactKeys;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * A match by normalized phone OR e-mail OR Telegram → 409 duplicate_candidate with the existing id,
     * unless $forceNew.
     *
     * @throws RecruitingException
     */
    public function create(User $actor, CandidateData $data, bool $forceNew = false): Candidate
    {
        $keys = $this->keys($data);
        if (! $forceNew && ($match = $this->candidates->findByContacts($keys)) !== null) {
            throw RecruitingException::duplicateCandidate($match[0]->id, $match[1]);
        }
        $vacancy = null;
        if ($data->vacancyId !== null) {
            $vacancy = $this->vacancies->find($data->vacancyId);
            if ($vacancy === null || ! $this->scope->canSeeVacancy($actor, $vacancy)) {
                throw RecruitingException::vacancyOutOfScope();
            }
        }

        return $this->applications->transaction(function () use ($actor, $data, $keys, $vacancy): Candidate {
            $candidate = $this->candidates->create([
                'full_name' => (string) $data->fullName,
                'phone' => $keys->phone,
                'email' => $keys->email,
                'telegram_username' => $keys->telegram,
                'city_id' => $data->cityId,
                'source' => ($data->source ?? CandidateSource::Manual)->value,
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
        ], static fn (mixed $v): bool => $v !== null);
        $keys = $this->keys($data);
        $contactAttrs = array_filter([
            'phone' => $keys->phone,
            'email' => $keys->email,
            'telegram_username' => $keys->telegram,
        ], static fn (mixed $v): bool => $v !== null);
        if ($contactAttrs !== []) {
            $match = $this->candidates->findByContacts($keys, $candidate->id);
            if ($match !== null) {
                throw RecruitingException::duplicateCandidate($match[0]->id, $match[1]);
            }
        }
        $attributes += $contactAttrs;
        if ($attributes === []) {
            return $candidate;
        }
        $this->candidates->update($candidate, $attributes);
        $this->log->info('recruiting.candidate_updated', ['id' => $candidate->id, 'by' => $actor->id, 'fields' => array_keys($attributes)]);

        return $candidate;
    }

    private function keys(CandidateData $data): ContactKeys
    {
        return $this->normalizer->keys($data->phone, $data->email, $data->telegram);
    }
}
