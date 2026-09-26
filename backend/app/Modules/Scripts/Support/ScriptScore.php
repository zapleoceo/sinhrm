<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Support;

/**
 * Score of an evaluation, the same for both engines (computed on the server, never taken from a model):
 * weights of done steps / all weights × 100; all weights 0 → share of done steps; no steps → 0.
 */
final class ScriptScore
{
    /** @param  list<array{weight: int, done: bool}>  $steps */
    public static function compute(array $steps): int
    {
        $total = 0;
        $done = 0;
        $doneCount = 0;
        foreach ($steps as $step) {
            $total += $step['weight'];
            if ($step['done']) {
                $done += $step['weight'];
                $doneCount++;
            }
        }
        $score = match (true) {
            $total > 0 => (int) round($done / $total * 100),
            $steps !== [] => (int) round($doneCount / count($steps) * 100),
            default => 0,
        };

        return max(0, min(100, $score));
    }
}
