<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

/**
 * Aggregates mood check-ins of a group for one period (pure). Each person counts once: their own mean first, then
 * the group mean of those — a person answering every day does not outweigh one answering once. Fewer distinct
 * people than the minimum group → suppressed (no average, no distribution, no count).
 */
final class MoodStats
{
    /**
     * @param  list<array{employee_id: int, score: int}>  $checkins
     * @return array{respondents: int|null, average: float|null, distribution: array<int, int>|null, suppressed: bool}
     */
    public static function bucket(array $checkins, int $minGroup): array
    {
        $byPerson = [];
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($checkins as $c) {
            $byPerson[$c['employee_id']][] = $c['score'];
            if (isset($distribution[$c['score']])) {
                $distribution[$c['score']]++;
            }
        }
        $people = count($byPerson);
        if ($people < max(1, $minGroup)) {
            return ['respondents' => null, 'average' => null, 'distribution' => null, 'suppressed' => true];
        }
        $means = array_map(static fn (array $scores): float => array_sum($scores) / count($scores), $byPerson);

        return [
            'respondents' => $people,
            'average' => round(array_sum($means) / $people, 2),
            'distribution' => $distribution,
            'suppressed' => false,
        ];
    }
}
