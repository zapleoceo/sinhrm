<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Repositories;

use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Pulse\Contracts\ResponseRepository;
use App\Modules\Pulse\Models\SurveyResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

final class EloquentResponseRepository implements ResponseRepository
{
    /**
     * @param  list<string>  $hashes
     * @return list<string>
     */
    public function answeredHashes(int $waveId, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return SurveyResponse::query()->where('wave_id', $waveId)->whereIn('respondent_hash', $hashes)
            ->pluck('respondent_hash')->map(static fn (mixed $h): string => (string) $h)->values()->all();
    }

    public function createOnce(array $attributes): bool
    {
        if (SurveyResponse::query()->where('wave_id', $attributes['wave_id'])
            ->where('respondent_hash', $attributes['respondent_hash'])->exists()) {
            return false;
        }
        try {
            SurveyResponse::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return false; // double submit racing
        }

        return true;
    }

    public function answersOf(int $waveId, ?int $departmentId = null): array
    {
        return SurveyResponse::query()->where('wave_id', $waveId)
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->orderBy('id')->get(['answers', 'branch_id', 'department_id'])
            ->map(static fn (SurveyResponse $r): array => [
                'answers' => $r->answers,
                'branch_id' => $r->branch_id,
                'department_id' => $r->department_id,
            ])->values()->all();
    }

    public function identified(int $waveId, int $limit): Collection
    {
        return SurveyResponse::query()->where('wave_id', $waveId)->whereNotNull('employee_id')
            ->orderBy('id')->limit($limit)->get(['id', 'employee_id', 'answers', 'submitted_on']);
    }

    public function segmentNames(string $segment, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = $segment === 'branch' ? Branch::query() : Department::query();

        return $query->whereIn('id', $ids)->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();
    }
}
