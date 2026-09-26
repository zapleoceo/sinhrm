<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Models\User;
use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Enums\WaveStatus;
use App\Modules\Pulse\Exceptions\PulseException;
use App\Modules\Pulse\Models\Survey;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Admin side of surveys: the builder (questions), waves (schedule, audience, anonymity, dates), closing a wave.
 * Questions freeze once a survey has responses (results and comparisons must keep matching question ids).
 */
final readonly class SurveyService
{
    public function __construct(private SurveyRepository $surveys, private WaveLifecycle $lifecycle) {}

    /** @return Collection<int, Survey> */
    public function list(): Collection
    {
        return $this->surveys->surveys();
    }

    public function find(int $id): Survey
    {
        return $this->surveys->findSurvey($id) ?? throw (new ModelNotFoundException)->setModel(Survey::class, [$id]);
    }

    /**
     * @param  array<string, mixed>  $data  validated: title, type, description?, questions, lifecycle_trigger?, active
     *
     * @throws PulseException has_responses
     */
    public function save(User $actor, ?Survey $survey, array $data): Survey
    {
        if ($survey !== null && $data['questions'] != $survey->questions && $this->surveys->hasResponses($survey)) {
            throw PulseException::hasResponses();
        }

        return $this->surveys->saveSurvey($survey, $data + ($survey === null ? ['created_by' => $actor->id] : []));
    }

    /** @throws PulseException has_responses (deactivate instead) */
    public function delete(Survey $survey): void
    {
        if ($this->surveys->hasResponses($survey)) {
            throw PulseException::hasResponses();
        }
        $this->surveys->deleteSurvey($survey);
    }

    /** @return Collection<int, SurveyWave> */
    public function waves(Survey $survey): Collection
    {
        return $this->surveys->waves($survey);
    }

    public function findWave(int $id): SurveyWave
    {
        return $this->surveys->findWave($id) ?? throw (new ModelNotFoundException)->setModel(SurveyWave::class, [$id]);
    }

    /**
     * @param  array{schedule: string, audience: array<string, list<int>>, anonymous: bool, min_group_size: int, starts_at: string, ends_at: string}  $data
     */
    public function createWave(User $actor, Survey $survey, array $data, ?Carbon $now = null): SurveyWave
    {
        $wave = $this->lifecycle->create($data + ['survey_id' => $survey->id, 'created_by' => $actor->id], $now ?? Carbon::now());

        return $this->findWave($wave->id);
    }

    /** @throws PulseException wave_not_open (already closed) */
    public function closeWave(SurveyWave $wave, ?Carbon $now = null): SurveyWave
    {
        if ($wave->status === WaveStatus::Closed) {
            throw PulseException::waveNotOpen();
        }
        $this->lifecycle->close($wave, $now ?? Carbon::now());

        return $this->findWave($wave->id);
    }
}
