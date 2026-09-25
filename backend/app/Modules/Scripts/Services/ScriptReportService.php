<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Models\User;
use App\Modules\Recruiting\DTO\DateRange;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Scripts\Contracts\EvaluationRepository;

/**
 * GET /api/reports/scripts: how well recruiters follow the scripts in a period (scope of the actor).
 * Steps are grouped by title: a step keeps its meaning across versions even when its id changes.
 */
final readonly class ScriptReportService
{
    /** Evaluations aggregated per request; the newest ones win when there are more. */
    public const int LIMIT = 5000;

    public function __construct(private EvaluationRepository $evaluations, private RecruitingScope $scope) {}

    /** @return array<string, mixed> */
    public function report(User $actor, DateRange $range): array
    {
        $rows = $this->evaluations->forReport($this->scope->for($actor), $range, self::LIMIT);

        $recruiters = [];
        $steps = [];
        $scoreSum = 0;
        $fixedSum = 0;
        foreach ($rows as $row) {
            $key = $row['author_id'] ?? 0;
            $r = $recruiters[$key] ?? ['author_id' => $row['author_id'], 'author_name' => $row['author_name'], 'evaluations' => 0, 'score_sum' => 0, 'fixed' => 0];
            $fixed = ($row['result']['next_step']['fixed'] ?? false) === true;
            $r['evaluations']++;
            $r['score_sum'] += $row['score'];
            $r['fixed'] += (int) $fixed;
            $recruiters[$key] = $r;
            $scoreSum += $row['score'];
            $fixedSum += (int) $fixed;

            foreach ((array) ($row['result']['steps'] ?? []) as $step) {
                $title = is_array($step) ? trim((string) ($step['title'] ?? '')) : '';
                if ($title === '') {
                    continue;
                }
                $s = $steps[$title] ?? ['title' => $title, 'required' => false, 'total' => 0, 'missed' => 0];
                $s['total']++;
                $s['missed'] += ($step['done'] ?? false) === true ? 0 : 1;
                $s['required'] = $s['required'] || ($step['required'] ?? false) === true;
                $steps[$title] = $s;
            }
        }

        $recruiterRows = array_map(static fn (array $r): array => [
            'author_id' => $r['author_id'],
            'author_name' => $r['author_name'],
            'evaluations' => $r['evaluations'],
            'avg_score' => round($r['score_sum'] / $r['evaluations'], 1),
            'next_step_fixed_pct' => self::pct($r['fixed'], $r['evaluations']),
        ], array_values($recruiters));
        usort($recruiterRows, static fn (array $a, array $b): int => [$b['evaluations'], $a['author_name']] <=> [$a['evaluations'], $b['author_name']]);

        $stepRows = array_map(static fn (array $s): array => $s + ['miss_rate_pct' => self::pct($s['missed'], $s['total'])], array_values($steps));
        usort($stepRows, static fn (array $a, array $b): int => [$b['miss_rate_pct'], $a['title']] <=> [$a['miss_rate_pct'], $b['title']]);

        $count = count($rows);

        return [
            'range' => $range->toArray(),
            'recruiters' => $recruiterRows,
            'steps' => $stepRows,
            'totals' => [
                'evaluations' => $count,
                'avg_score' => $count === 0 ? 0 : round($scoreSum / $count, 1),
                'next_step_fixed_pct' => self::pct($fixedSum, $count),
                'truncated' => $count >= self::LIMIT,
            ],
        ];
    }

    private static function pct(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total * 100, 1);
    }
}
