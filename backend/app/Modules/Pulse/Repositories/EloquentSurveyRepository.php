<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Repositories;

use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Enums\LifecycleTrigger;
use App\Modules\Pulse\Enums\WaveStatus;
use App\Modules\Pulse\Models\Survey;
use App\Modules\Pulse\Models\SurveyResponse;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentSurveyRepository implements SurveyRepository
{
    public function surveys(): Collection
    {
        return Survey::query()->withCount('waves')->orderByDesc('id')->get();
    }

    public function findSurvey(int $id): ?Survey
    {
        return Survey::query()->withCount('waves')->find($id);
    }

    public function saveSurvey(?Survey $survey, array $attributes): Survey
    {
        $survey ??= new Survey;
        $survey->fill($attributes)->save();

        return $survey->loadCount('waves');
    }

    public function deleteSurvey(Survey $survey): void
    {
        $survey->delete();
    }

    public function activeLifecycle(LifecycleTrigger $trigger): Collection
    {
        return Survey::query()->where('lifecycle_trigger', $trigger->value)->where('active', true)->orderBy('id')->get();
    }

    public function waves(Survey $survey): Collection
    {
        return SurveyWave::query()->with('survey')->where('survey_id', $survey->id)->withCount('responses')
            ->orderByDesc('starts_at')->orderByDesc('id')->limit(200)->get();
    }

    public function findWave(int $id): ?SurveyWave
    {
        return SurveyWave::query()->with('survey')->withCount('responses')->find($id);
    }

    public function createWave(array $attributes): SurveyWave
    {
        return SurveyWave::query()->create($attributes);
    }

    public function createLifecycleOnce(array $attributes): ?SurveyWave
    {
        return $this->createUnique($attributes, [
            'survey_id' => $attributes['survey_id'],
            'subject_employee_id' => $attributes['subject_employee_id'],
            'trigger_key' => $attributes['trigger_key'],
        ]);
    }

    public function createSuccessorOnce(array $attributes): ?SurveyWave
    {
        return $this->createUnique($attributes, ['parent_wave_id' => $attributes['parent_wave_id']]);
    }

    public function updateWave(SurveyWave $wave, array $attributes): SurveyWave
    {
        $wave->fill($attributes)->save();

        return $wave;
    }

    public function openWaves(): Collection
    {
        return SurveyWave::query()->with('survey')->where('status', WaveStatus::Open->value)->orderBy('ends_at')->get();
    }

    public function dueToOpen(Carbon $now): Collection
    {
        return SurveyWave::query()->where('status', WaveStatus::Scheduled->value)->where('starts_at', '<=', $now)
            ->orderBy('id')->limit(200)->get();
    }

    public function dueToClose(Carbon $now): Collection
    {
        return SurveyWave::query()->with('survey')->where('status', WaveStatus::Open->value)->where('ends_at', '<=', $now)
            ->orderBy('id')->limit(200)->get();
    }

    public function previousWave(SurveyWave $wave): ?SurveyWave
    {
        return SurveyWave::query()->with('survey')->where('survey_id', $wave->survey_id)->whereNull('subject_employee_id')
            ->whereKeyNot($wave->id)->where('status', '!=', WaveStatus::Scheduled->value)
            ->where('starts_at', '<', $wave->starts_at)
            ->orderByDesc('starts_at')->orderByDesc('id')->first();
    }

    public function hasResponses(Survey $survey): bool
    {
        return SurveyResponse::query()->whereIn('wave_id', SurveyWave::query()->where('survey_id', $survey->id)->select('id'))->exists();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $key
     */
    private function createUnique(array $attributes, array $key): ?SurveyWave
    {
        if (SurveyWave::query()->where($key)->exists()) {
            return null;
        }
        try {
            return SurveyWave::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return null; // created concurrently (overlapping cron / duplicate event)
        }
    }
}
