<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Audit\Contracts\AuditContext;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\PipelineStage;
use Illuminate\Support\Facades\Gate;

/**
 * Bulk actions on the candidates list. Every item goes through the same policy and service method as the single
 * action (ApplicationService::move, CandidateService::update); one failure does not stop the rest.
 * Per-id errors never say more than the actor may already know: a candidate out of their scope is "not_found"
 * whether they exist or not, and a vacancy out of their scope stops the whole request (vacancy_out_of_scope).
 */
final readonly class CandidateBulkService
{
    public function __construct(
        private CandidateRepository $candidates,
        private ApplicationRepository $applications,
        private VacancyRepository $vacancies,
        private ApplicationService $applicationService,
        private CandidateService $candidateService,
        private RecruitingScope $scope,
        private AuditContext $audit,
    ) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $input  validated BulkCandidatesRequest
     * @return list<array{id: int, ok: bool, error: string|null}>
     */
    public function run(User $actor, string $action, array $ids, array $input): array
    {
        $rejectStage = null;
        if ($action === 'move' || $action === 'reject') {
            // The vacancy is checked once for the whole request (as the clipper does): otherwise the per-id errors
            // answer "does this candidate exist / did they apply here" for vacancies the actor may not see at all.
            $vacancy = $this->vacancies->find((int) $input['vacancy_id']);
            if ($vacancy === null || ! $this->scope->canSeeVacancy($actor, $vacancy)) {
                throw RecruitingException::vacancyOutOfScope();
            }
            $rejectStage = $vacancy->pipeline->stages->first(static fn (PipelineStage $s): bool => $s->isReject());
        }
        $results = [];
        foreach ($ids as $id) {
            $error = null;
            try {
                $error = $this->audit->within(['bulk' => 'candidates.'.$action], fn (): ?string => $this->one($actor, $action, $id, $input, $rejectStage));
            } catch (RecruitingException $e) {
                $error = $e->errorCode;
            }
            $results[] = ['id' => $id, 'ok' => $error === null, 'error' => $error];
        }

        return $results;
    }

    /** @param  array<string, mixed>  $input */
    private function one(User $actor, string $action, int $id, array $input, ?PipelineStage $rejectStage): ?string
    {
        $candidate = $this->candidates->find($id);
        // One generic answer for "no such candidate" and "not yours": the caller must not be able to tell them apart.
        if ($candidate === null || ! $this->scope->canSeeCandidate($actor, $candidate)) {
            return 'not_found';
        }
        if ($action === 'move' || $action === 'reject') {
            $application = $this->applications->findFor($id, (int) $input['vacancy_id']);
            if ($application === null) {
                return 'no_application';
            }
            if (Gate::forUser($actor)->denies('move', $application)) {
                return 'forbidden';
            }
            $stageId = $action === 'move' ? (int) $input['stage_id'] : $rejectStage?->id;
            if ($stageId === null) {
                return 'stage_not_in_pipeline';
            }
            $reasonId = isset($input['reject_reason_id']) ? (int) $input['reject_reason_id'] : null;
            $reason = isset($input['reason']) ? (string) $input['reason'] : null;
            $this->applicationService->move($actor, $application, new MoveData($stageId, $reason, $reasonId));

            return null;
        }
        if (Gate::forUser($actor)->denies('update', $candidate)) {
            return 'forbidden';
        }
        $data = $action === 'tag'
            ? new CandidateData(tags: $this->withTag($candidate, (string) $input['tag']))
            : new CandidateData(ownerId: (int) $input['owner_id']);
        $this->candidateService->update($actor, $candidate, $data);

        return null;
    }

    /** @return list<string> */
    private function withTag(Candidate $candidate, string $tag): array
    {
        $tags = $candidate->tags ?? [];

        return in_array($tag, $tags, true) ? $tags : [...$tags, trim($tag)];
    }
}
