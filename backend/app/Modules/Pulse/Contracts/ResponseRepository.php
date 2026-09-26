<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Contracts;

use App\Modules\Pulse\Models\SurveyResponse;
use Illuminate\Database\Eloquent\Collection;

/** Survey responses. Reads never return respondent hashes (hidden on the model and not selected). */
interface ResponseRepository
{
    /**
     * @param  list<string>  $hashes
     * @return list<string> those of $hashes already answered in the wave
     */
    public function answeredHashes(int $waveId, array $hashes): array;

    /**
     * Inserts once per (wave, respondent hash); false when this person has already answered.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOnce(array $attributes): bool;

    /**
     * Answers maps of a wave, optionally only one segment; with segment ids for breakdowns.
     *
     * @return list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>
     */
    public function answersOf(int $waveId, ?int $departmentId = null): array;

    /** @return Collection<int, SurveyResponse> identified responses (non-anonymous waves only), with employee id */
    public function identified(int $waveId, int $limit): Collection;

    /**
     * Names of branches ($segment = branch) or departments (department) for breakdown rows.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function segmentNames(string $segment, array $ids): array;
}
