<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

use App\Modules\Pulse\Enums\QuestionType;
use App\Modules\Pulse\Exceptions\PulseException;

/**
 * Checks answers against the survey's questions (pure) and normalizes them:
 * numeric → int in range; single → option index; multi → unique sorted option indexes; text → trimmed ≤ 2000 chars.
 * Unknown question ids are dropped; a missing required answer fails.
 */
final class AnswerValidator
{
    private const int TEXT_MAX = 2000;

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, int|string|list<int>>
     *
     * @throws PulseException invalid_answers {question ids}
     */
    public static function validate(array $questions, array $answers): array
    {
        $out = [];
        $errors = [];
        foreach ($questions as $q) {
            $id = (string) $q['id'];
            $type = QuestionType::from((string) $q['type']);
            $value = $answers[$id] ?? null;
            if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
                if ((bool) ($q['required'] ?? false)) {
                    $errors[] = $id;
                }

                continue;
            }
            $normalized = self::one($type, $value, count((array) ($q['options'] ?? [])));
            if ($normalized === null) {
                $errors[] = $id;

                continue;
            }
            $out[$id] = $normalized;
        }
        if ($errors !== []) {
            throw PulseException::invalidAnswers($errors);
        }

        return $out;
    }

    /** @return int|string|list<int>|null */
    private static function one(QuestionType $type, mixed $value, int $options): int|string|array|null
    {
        if ($type->isNumeric()) {
            [$min, $max] = $type->range() ?? [0, 0];

            return self::int($value, $min, $max);
        }
        if ($type === QuestionType::Text) {
            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, self::TEXT_MAX) : null;
        }
        if ($type === QuestionType::Single) {
            return self::int($value, 0, $options - 1);
        }
        if (! is_array($value)) {
            return null;
        }
        $picked = [];
        foreach ($value as $v) {
            $index = self::int($v, 0, $options - 1);
            if ($index === null) {
                return null;
            }
            $picked[$index] = $index;
        }
        sort($picked);

        return $picked;
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            $int = (int) $value;

            return $int >= $min && $int <= $max ? $int : null;
        }

        return null;
    }
}
