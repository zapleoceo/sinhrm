<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

use App\Modules\Pulse\Enums\QuestionType;

/**
 * Survey results from raw answers (pure, no DB, no identities in or out).
 *
 * - summary(): per question — scales: average + distribution; eNPS: score and groups; choices: counts per option;
 *   text: the answers sorted alphabetically (submission order could point to a person). Fewer answers than the
 *   minimum group → suppressed (no numbers, no texts, not even the exact count).
 * - headline(): one comparable number per numeric question (scale average, eNPS score) for wave comparison.
 */
final class WaveResults
{
    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array<string, mixed>>  $answers  one answers map per response
     * @return array{responses: int|null, suppressed: bool, questions: list<array<string, mixed>>}
     */
    public static function summary(array $questions, array $answers, int $minGroup): array
    {
        $count = count($answers);
        if ($count < max(1, $minGroup)) {
            return ['responses' => null, 'suppressed' => true, 'questions' => []];
        }
        $out = [];
        foreach ($questions as $q) {
            $result = self::question($q, $answers);
            // An optional question answered by fewer people than the minimum is hidden on its own.
            $out[] = $result['answered'] < max(1, $minGroup)
                ? ['id' => $result['id'], 'type' => $result['type'], 'text' => $result['text'], 'answered' => null, 'suppressed' => true]
                : $result + ['suppressed' => false];
        }

        return ['responses' => $count, 'suppressed' => false, 'questions' => $out];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  list<array<string, mixed>>  $answers
     */
    public static function headline(array $question, array $answers): ?float
    {
        $type = QuestionType::tryFrom((string) ($question['type'] ?? ''));
        if ($type === null || ! $type->isNumeric()) {
            return null;
        }
        $values = self::numbers($question, $answers);
        if ($values === []) {
            return null;
        }

        return $type === QuestionType::Enps
            ? (float) Enps::calculate($values)['score']
            : round(array_sum($values) / count($values), 2);
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  list<array<string, mixed>>  $answers
     * @return array<string, mixed>
     */
    private static function question(array $question, array $answers): array
    {
        $id = (string) $question['id'];
        $type = QuestionType::from((string) $question['type']);
        $base = ['id' => $id, 'type' => $type->value, 'text' => (string) $question['text']];
        if ($type->isNumeric()) {
            $values = self::numbers($question, $answers);
            [$min, $max] = $type->range() ?? [0, 0];
            $distribution = array_fill_keys(array_map('strval', range($min, $max)), 0);
            foreach ($values as $v) {
                $distribution[(string) $v]++;
            }
            $average = $values === [] ? null : round(array_sum($values) / count($values), 2);

            return $base + ['answered' => count($values), 'average' => $average, 'distribution' => $distribution]
                + ($type === QuestionType::Enps ? ['enps' => Enps::calculate($values)] : []);
        }
        if ($type === QuestionType::Text) {
            $texts = [];
            foreach ($answers as $a) {
                $text = is_string($a[$id] ?? null) ? trim((string) $a[$id]) : '';
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
            sort($texts, SORT_STRING);

            return $base + ['answered' => count($texts), 'texts' => $texts];
        }
        $options = array_values(array_map('strval', (array) ($question['options'] ?? [])));
        $counts = array_fill(0, count($options), 0);
        $answered = 0;
        foreach ($answers as $a) {
            $picked = $a[$id] ?? null;
            $picked = is_array($picked) ? $picked : ($picked === null ? [] : [$picked]);
            if ($picked !== []) {
                $answered++;
            }
            foreach ($picked as $index) {
                if (is_int($index) && isset($counts[$index])) {
                    $counts[$index]++;
                }
            }
        }

        return $base + ['answered' => $answered, 'options' => array_map(
            static fn (string $label, int $count): array => ['label' => $label, 'count' => $count],
            $options,
            $counts,
        )];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  list<array<string, mixed>>  $answers
     * @return list<int>
     */
    private static function numbers(array $question, array $answers): array
    {
        $id = (string) $question['id'];
        $values = [];
        foreach ($answers as $a) {
            if (is_int($a[$id] ?? null)) {
                $values[] = $a[$id];
            }
        }

        return $values;
    }
}
