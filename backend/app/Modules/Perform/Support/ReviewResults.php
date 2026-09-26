<?php

declare(strict_types=1);

namespace App\Modules\Perform\Support;

use App\Modules\Perform\Enums\ReviewType;

/**
 * Aggregates submitted review answers about one subject (pure, no DB).
 *
 * - Scores: mean rating per competency per reviewer type; "average" — mean of the non-self type scores.
 * - Protected types (peer, upward): shown only when at least MIN_REVIEWERS distinct reviewers submitted; below that
 *   the group is "suppressed": no score, no reviewer count, no comments. While the cycle is still active (not
 *   final) protected groups are always suppressed and show only a coarse completion range ("0–2", "3–4", "5+"):
 *   polling live results between two submissions would otherwise reveal the newest rating and comment. Their reviewers are never named in an
 *   anonymous cycle, and their comments are sorted by text (the order of submission would give people away).
 */
final class ReviewResults
{
    public const int MIN_REVIEWERS = 3;

    /**
     * @param  list<ReviewType>  $types  types of the cycle
     * @param  list<array{id: int, name: string, max: int}>  $competencies
     * @param  list<array{type: string, reviewer_id: int, reviewer_name: string, competency_id: int, rating: int, comment: string|null}>  $rows
     * @return array{groups: array<string, array{reviewers: int|null, suppressed: bool, submitted?: string}>, competencies: list<array{id: int, name: string, max: int, scores: array<string, float|null>, average: float|null}>, comments: list<array{type: string, competency_id: int, text: string, author: string|null}>}
     */
    public static function aggregate(array $types, array $competencies, array $rows, bool $anonymous, bool $final = true): array
    {
        $reviewers = [];
        foreach ($rows as $row) {
            $reviewers[$row['type']][$row['reviewer_id']] = true;
        }
        $groups = [];
        $visible = [];
        foreach ($types as $type) {
            $count = count($reviewers[$type->value] ?? []);
            $suppressed = $type->isProtected() && (! $final || $count < self::MIN_REVIEWERS);
            $groups[$type->value] = ['reviewers' => $suppressed ? null : $count, 'suppressed' => $suppressed]
                + ($type->isProtected() && ! $final ? ['submitted' => self::completion($count)] : []);
            $visible[$type->value] = ! $suppressed;
        }

        $sums = [];
        $comments = [];
        foreach ($rows as $row) {
            if (! ($visible[$row['type']] ?? false)) {
                continue;
            }
            $sums[$row['competency_id']][$row['type']][] = $row['rating'];
            $text = trim((string) $row['comment']);
            if ($text !== '') {
                $protected = ReviewType::from($row['type'])->isProtected();
                $comments[] = [
                    'type' => $row['type'],
                    'competency_id' => $row['competency_id'],
                    'text' => $text,
                    'author' => $protected && $anonymous ? null : $row['reviewer_name'],
                ];
            }
        }
        usort($comments, static fn (array $a, array $b): int => [$a['type'], $a['competency_id'], $a['text']] <=> [$b['type'], $b['competency_id'], $b['text']]);

        $out = [];
        foreach ($competencies as $c) {
            $scores = [];
            $others = [];
            foreach ($types as $type) {
                $ratings = $sums[$c['id']][$type->value] ?? [];
                $score = $ratings === [] ? null : round(array_sum($ratings) / count($ratings), 2);
                $scores[$type->value] = $score;
                if ($score !== null && $type !== ReviewType::Self) {
                    $others[] = $score;
                }
            }
            $out[] = $c + ['scores' => $scores, 'average' => $others === [] ? null : round(array_sum($others) / count($others), 2)];
        }

        return ['groups' => $groups, 'competencies' => $out, 'comments' => $comments];
    }

    /** Coarse completion of a protected group in an active cycle. */
    public static function completion(int $count): string
    {
        return $count < 3 ? '0–2' : ($count < 5 ? '3–4' : '5+');
    }
}
