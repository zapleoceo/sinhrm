<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Contracts;

use App\Modules\Pulse\Enums\LifecycleTrigger;
use App\Modules\Pulse\Models\Survey;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Surveys and their waves. */
interface SurveyRepository
{
    /** @return Collection<int, Survey> newest first, with waves_count */
    public function surveys(): Collection;

    public function findSurvey(int $id): ?Survey;

    /** @param  array<string, mixed>  $attributes */
    public function saveSurvey(?Survey $survey, array $attributes): Survey;

    public function deleteSurvey(Survey $survey): void;

    /** @return Collection<int, Survey> active surveys with this lifecycle trigger */
    public function activeLifecycle(LifecycleTrigger $trigger): Collection;

    /** @return Collection<int, SurveyWave> of the survey, newest first, with responses_count */
    public function waves(Survey $survey): Collection;

    public function findWave(int $id): ?SurveyWave;

    /** @param  array<string, mixed>  $attributes */
    public function createWave(array $attributes): SurveyWave;

    /**
     * Lifecycle wave once per (survey, employee, trigger key); null when it already existed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createLifecycleOnce(array $attributes): ?SurveyWave;

    /**
     * Successor of a recurring wave, once (unique parent_wave_id); null when it already existed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createSuccessorOnce(array $attributes): ?SurveyWave;

    /** @param  array<string, mixed>  $attributes */
    public function updateWave(SurveyWave $wave, array $attributes): SurveyWave;

    /** @return Collection<int, SurveyWave> open waves with survey */
    public function openWaves(): Collection;

    /** @return Collection<int, SurveyWave> scheduled waves whose start has come */
    public function dueToOpen(Carbon $now): Collection;

    /** @return Collection<int, SurveyWave> open waves whose end has passed */
    public function dueToClose(Carbon $now): Collection;

    /** Latest earlier non-lifecycle wave of the same survey that has started (for comparison). */
    public function previousWave(SurveyWave $wave): ?SurveyWave;

    /**
     * Closed non-lifecycle waves of the same survey that started before $wave, oldest first.
     *
     * @return list<SurveyWave>
     */
    public function closedWavesBefore(SurveyWave $wave): array;

    public function hasResponses(Survey $survey): bool;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
