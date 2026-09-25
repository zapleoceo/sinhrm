<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\PipelineStage;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Applications and their route through the vacancy's pipeline. Every step is a stage_change plus a "system"
 * touchpoint (which never counts as a contact for staleness).
 */
final readonly class ApplicationService
{
    public function __construct(
        private ApplicationRepository $applications,
        private PipelineRepository $pipelines,
        private TouchpointRepository $touchpoints,
        private LoggerInterface $log,
    ) {}

    /** @throws ModelNotFoundException<Application> */
    public function find(int $id): Application
    {
        return $this->applications->find($id) ?? throw (new ModelNotFoundException)->setModel(Application::class, [$id]);
    }

    /**
     * Puts the candidate on the vacancy's first stage.
     *
     * @throws RecruitingException already_applied (409)
     */
    public function apply(?User $actor, Candidate $candidate, Vacancy $vacancy, ?Carbon $at = null): Application
    {
        $existing = $this->applications->findFor($candidate->id, $vacancy->id);
        if ($existing !== null) {
            throw RecruitingException::alreadyApplied($existing->id);
        }
        $stage = $this->pipelines->firstStage($vacancy->pipeline_id) ?? throw RecruitingException::noDefaultPipeline();
        $at ??= Carbon::now();

        return $this->applications->transaction(function () use ($actor, $candidate, $vacancy, $stage, $at): Application {
            $application = $this->applications->create([
                'candidate_id' => $candidate->id,
                'vacancy_id' => $vacancy->id,
                'stage_id' => $stage->id,
                'status' => ApplicationStatus::Active->value,
                'stage_entered_at' => $at,
            ]);
            $this->recordStep($actor, $application, null, $stage, null, $at);

            return $application;
        });
    }

    /**
     * Moves the application to another stage of its vacancy's pipeline.
     * Terminal "closed" stage → rejected, needs reject_reason_id; terminal "hire" stage → hired;
     * other stages → active (reopens a closed application, clears the rejection).
     *
     * @throws RecruitingException
     */
    public function move(User $actor, Application $application, MoveData $data, ?Carbon $at = null): Application
    {
        $vacancy = $application->vacancy;
        $to = $this->pipelines->findStage($data->stageId);
        if ($to === null || $to->pipeline_id !== $vacancy->pipeline_id) {
            throw RecruitingException::stageNotInPipeline();
        }
        if ($to->id === $application->stage_id) {
            throw RecruitingException::sameStage();
        }
        if ($to->isReject() && $data->rejectReasonId === null) {
            throw RecruitingException::rejectReasonRequired();
        }
        $from = $application->stage;
        $status = $to->applicationStatus();
        $at ??= Carbon::now();

        $this->applications->transaction(function () use ($actor, $application, $data, $from, $to, $status, $at): void {
            $this->applications->update($application, [
                'stage_id' => $to->id,
                'status' => $status->value,
                'stage_entered_at' => $at,
                'closed_at' => $status === ApplicationStatus::Active ? null : $at,
                'reject_reason_id' => $status === ApplicationStatus::Rejected ? $data->rejectReasonId : null,
                'rejected_note' => $status === ApplicationStatus::Rejected ? $data->reason : null,
            ]);
            $this->recordStep($actor, $application, $from, $to, $data->reason, $at);
        });
        $this->log->info('recruiting.application_moved', [
            'id' => $application->id,
            'from' => $from->id,
            'to' => $to->id,
            'by' => $actor->id,
        ]);

        return $this->find($application->id);
    }

    private function recordStep(?User $actor, Application $application, ?PipelineStage $from, PipelineStage $to, ?string $reason, Carbon $at): StageChange
    {
        $change = $this->applications->recordStageChange([
            'application_id' => $application->id,
            'from_stage_id' => $from?->id,
            'to_stage_id' => $to->id,
            'by_user_id' => $actor?->id,
            'reason' => $reason,
            'at' => $at,
        ]);
        $this->touchpoints->create([
            'candidate_id' => $application->candidate_id,
            'application_id' => $application->id,
            'stage_change_id' => $change->id,
            'channel' => Channel::System->value,
            'direction' => Direction::Out->value,
            'author_id' => $actor?->id,
            'occurred_at' => $at,
            'body' => ($from !== null ? $from->name.' → ' : '→ ').$to->name,
            'meta' => ['from_stage_id' => $from?->id, 'to_stage_id' => $to->id],
            'via_product' => true,
        ]);

        return $change;
    }
}
