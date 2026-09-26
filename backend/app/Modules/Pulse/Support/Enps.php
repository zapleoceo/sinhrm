<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

/**
 * Employee Net Promoter Score from 0–10 answers (pure).
 * Promoters answer 9–10, passives 7–8, detractors 0–6; eNPS = %promoters − %detractors, rounded, from −100 to 100.
 */
final class Enps
{
    /**
     * @param  list<int>  $values
     * @return array{score: int|null, promoters: int, passives: int, detractors: int, total: int}
     */
    public static function calculate(array $values): array
    {
        $promoters = $passives = $detractors = 0;
        foreach ($values as $value) {
            if ($value >= 9) {
                $promoters++;
            } elseif ($value >= 7) {
                $passives++;
            } else {
                $detractors++;
            }
        }
        $total = count($values);

        return [
            'score' => $total === 0 ? null : (int) round(($promoters - $detractors) / $total * 100),
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'total' => $total,
        ];
    }
}
