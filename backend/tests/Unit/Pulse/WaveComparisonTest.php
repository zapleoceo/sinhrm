<?php

declare(strict_types=1);

namespace Tests\Unit\Pulse;

use App\Modules\Pulse\Models\SurveyWave;
use App\Modules\Pulse\Support\WaveComparison;
use PHPUnit\Framework\TestCase;

/** The comparison arithmetic extracted from ResponseService::compare. */
final class WaveComparisonTest extends TestCase
{
    public function test_group_by_skips_rows_without_a_segment(): void
    {
        $rows = [
            ['answers' => [], 'branch_id' => 2, 'department_id' => null],
            ['answers' => [], 'branch_id' => null, 'department_id' => 5],
            ['answers' => [], 'branch_id' => 2, 'department_id' => 5],
        ];

        $this->assertSame([2 => [$rows[0], $rows[2]]], WaveComparison::groupBy($rows, 'branch_id'));
        $this->assertSame([5 => [$rows[1], $rows[2]]], WaveComparison::groupBy($rows, 'department_id'));
    }

    public function test_row_shows_delta_only_when_safe_and_above_the_minimum(): void
    {
        $wave = (new SurveyWave)->forceFill(['min_group_size' => 2]);
        $previous = (new SurveyWave)->forceFill(['min_group_size' => 2]);
        $question = ['id' => 'q1', 'type' => 'scale5', 'text' => 'Q'];
        $now = [self::answer(4), self::answer(5)];
        $then = [self::answer(3), self::answer(3)];

        $this->assertSame(
            ['segment' => null, 'name' => null, 'questions' => [['id' => 'q1', 'current' => 4.5, 'previous' => 3.0, 'delta' => 1.5]], 'hidden_reason' => null],
            WaveComparison::row(null, null, [$question], $now, $then, $wave, $previous, true),
        );
        $this->assertSame(
            ['segment' => 7, 'name' => 'Kyiv', 'questions' => [['id' => 'q1', 'current' => 4.5, 'previous' => null, 'delta' => null]], 'hidden_reason' => 'anonymity'],
            WaveComparison::row(7, 'Kyiv', [$question], $now, $then, $wave, $previous, false),
        );
        $this->assertNull(WaveComparison::row(null, null, [$question], [self::answer(4)], $then, $wave, $previous, true)['questions'][0]['current']);
    }

    /** @return array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null} */
    private static function answer(int $score): array
    {
        return ['answers' => ['q1' => $score], 'branch_id' => null, 'department_id' => null];
    }
}
