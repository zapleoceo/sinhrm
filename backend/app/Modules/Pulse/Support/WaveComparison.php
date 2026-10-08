<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

use App\Modules\Pulse\Models\SurveyWave;

/**
 * Pure arithmetic of the wave-over-wave comparison (ResponseService::compare decides what is safe to show):
 * grouping answers by segment and one comparison row — the headline of every numeric question now and before, below
 * the wave's minimum group a null, and the previous value and delta withheld when the row is not safe.
 */
final class WaveComparison
{
    /**
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $rows
     * @return array<int, list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>> segment id to rows; rows without a segment stay only in the total
     */
    public static function groupBy(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $id = $row[$key] ?? null;
            if ($id !== null) {
                $groups[(int) $id][] = $row;
            }
        }

        return $groups;
    }

    /**
     * One row of the comparison. When the audiences of the two waves differ by only a few people ($safe = false),
     * the previous value and the delta are withheld (hidden_reason = anonymity): subtracting them would expose the
     * answers of the people who joined or left.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $now
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $then
     * @return array<string, mixed>
     */
    public static function row(?int $id, ?string $name, array $questions, array $now, array $then, SurveyWave $wave, ?SurveyWave $previous, bool $safe): array
    {
        $cells = array_map(static fn (array $q): array => self::delta($q, $now, $safe ? $then : [], $wave, $safe ? $previous : null), $questions);

        return ['segment' => $id, 'name' => $name, 'questions' => $cells, 'hidden_reason' => $safe ? null : 'anonymity'];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $now
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $then
     * @return array{id: string, current: float|null, previous: float|null, delta: float|null}
     */
    private static function delta(array $question, array $now, array $then, SurveyWave $wave, ?SurveyWave $previous): array
    {
        $current = count($now) >= max(1, $wave->min_group_size) ? WaveResults::headline($question, array_column($now, 'answers')) : null;
        $before = $previous !== null && count($then) >= max(1, $previous->min_group_size)
            ? WaveResults::headline($question, array_column($then, 'answers')) : null;

        return [
            'id' => (string) $question['id'],
            'current' => $current,
            'previous' => $before,
            'delta' => $current === null || $before === null ? null : round($current - $before, 2),
        ];
    }
}
