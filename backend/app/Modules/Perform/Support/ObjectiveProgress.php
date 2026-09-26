<?php

declare(strict_types=1);

namespace App\Modules\Perform\Support;

/**
 * Objective progress from its key results (pure, no DB).
 * One key result: (current − start) / (target − start), clamped to 0..1 (works for "decrease" metrics too, where
 * target < start). start = target: done when current reached it. Objective: weighted mean × 100, rounded.
 */
final class ObjectiveProgress
{
    /** @param  list<array<string, mixed>>  $keyResults */
    public static function of(array $keyResults): int
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($keyResults as $kr) {
            $weight = max(0.0, self::num($kr['weight'] ?? 1));
            $sum += self::keyResult($kr) * $weight;
            $weights += $weight;
        }

        return $weights <= 0.0 ? 0 : (int) round($sum / $weights * 100);
    }

    /** @param  array<string, mixed>  $kr  0..1 */
    public static function keyResult(array $kr): float
    {
        $start = self::num($kr['start'] ?? 0);
        $target = self::num($kr['target'] ?? 0);
        $current = self::num($kr['current'] ?? $start);
        if ($target === $start) {
            return $current >= $target ? 1.0 : 0.0;
        }

        return max(0.0, min(1.0, ($current - $start) / ($target - $start)));
    }

    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
