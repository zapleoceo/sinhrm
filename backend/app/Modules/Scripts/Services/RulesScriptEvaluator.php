<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Modules\Scripts\Contracts\ScriptEvaluator;
use App\Modules\Scripts\DTO\EvaluationResult;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\EvaluationEngine;
use App\Modules\Scripts\Support\ScriptScore;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic evaluation without AI:
 * - a step is done when any of its keywords occurs in a sentence (case-insensitive); that sentence is the quote;
 * - score = weights of done steps / all weights × 100 (all weights 0 → share of done steps);
 * - "next step fixed" = a positive pattern (regex, e.g. «завтра», «записал») occurs AFTER the last negative one
 *   (e.g. «подумайте»): the conversation ends with a concrete agreement, not with "think about it";
 * - an objection counts as raised when its trigger phrase occurs;
 * - recommendations: missed required steps, next step not fixed, negative phrase used.
 */
final class RulesScriptEvaluator implements ScriptEvaluator
{
    private const int QUOTE_MAX = 300;

    public function engine(): EvaluationEngine
    {
        return EvaluationEngine::Rules;
    }

    public function evaluate(ScriptContent $script, string $text): EvaluationResult
    {
        $sentences = self::sentences($text);
        $lower = array_map(static fn (string $s): string => mb_strtolower($s), $sentences);

        $steps = [];
        $recommendations = [];
        foreach ($script->steps as $step) {
            $index = self::findAny($lower, $step->keywords);
            $done = $index !== null;
            $steps[] = [
                'id' => $step->id,
                'title' => $step->title,
                'required' => $step->required,
                'weight' => $step->weight,
                'done' => $done,
                'quote' => $done ? self::quote($sentences[$index]) : null,
            ];
            if (! $done && $step->required) {
                $recommendations[] = ['type' => 'missed_step', 'step_id' => $step->id, 'title' => $step->title];
            }
        }
        $score = ScriptScore::compute($steps);

        $positive = self::lastMatch($sentences, $script->nextStepPatterns['positive']);
        $negative = self::lastMatch($sentences, $script->nextStepPatterns['negative']);
        $fixed = $positive !== null && ($negative === null || $positive > $negative);
        if (! $fixed) {
            $recommendations[] = ['type' => 'next_step_not_fixed'];
        }
        if ($negative !== null && ! $fixed) {
            $recommendations[] = ['type' => 'negative_phrase', 'quote' => self::quote($sentences[$negative])];
        }

        $objections = [];
        foreach ($script->objections as $objection) {
            $trigger = mb_strtolower($objection['trigger']);
            $index = $trigger === '' ? null : self::findAny($lower, [$trigger]);
            $objections[] = [
                'id' => $objection['id'],
                'trigger' => $objection['trigger'],
                'raised' => $index !== null,
                'quote' => $index === null ? null : self::quote($sentences[$index]),
            ];
        }

        return new EvaluationResult(
            engine: EvaluationEngine::Rules,
            score: $score,
            steps: $steps,
            nextStep: [
                'fixed' => $fixed,
                'quote' => $positive === null ? null : self::quote($sentences[$positive]),
                'negative_quote' => $negative === null ? null : self::quote($sentences[$negative]),
            ],
            objections: $objections,
            recommendations: $recommendations,
        );
    }

    /**
     * Sentences (and lines) of the text, trimmed, non-empty.
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+|\R+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /** Longest accepted next-step pattern. */
    public const int PATTERN_MAX = 200;

    /** PCRE backtracking budget per pattern × sentence while evaluating (ReDoS guard). */
    public const int BACKTRACK_LIMIT = 10000;

    /**
     * True when the pattern is a valid, bounded regex fragment (checked when a draft is saved): at most PATTERN_MAX
     * characters and no nested quantifiers such as (a+)+, (a*)*, (.*)+ — the classic catastrophic backtracking shapes.
     */
    public static function isValidPattern(string $pattern): bool
    {
        if (mb_strlen($pattern) > self::PATTERN_MAX || self::hasNestedQuantifier($pattern)) {
            return false;
        }

        return @preg_match(self::regex($pattern), '') !== false;
    }

    /** A group that contains a quantifier (+, *, {n,}) and is itself quantified. */
    public static function hasNestedQuantifier(string $pattern): bool
    {
        return preg_match('/\((?:[^()\\\\]|\\\\.)*(?:[+*]|\{\d*,\d*\})(?:[^()\\\\]|\\\\.)*\)\s*(?:[+*]|\{\d*,?\d*\})/u', $pattern) === 1;
    }

    /**
     * @param  list<string>  $lowerSentences
     * @param  list<string>  $needles  lowercase
     */
    private static function findAny(array $lowerSentences, array $needles): ?int
    {
        if ($needles === []) {
            return null;
        }
        foreach ($lowerSentences as $i => $sentence) {
            foreach ($needles as $needle) {
                if ($needle !== '' && mb_strpos($sentence, $needle) !== false) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Index of the last sentence matching any pattern.
     *
     * @param  list<string>  $sentences
     * @param  list<string>  $patterns
     */
    private static function lastMatch(array $sentences, array $patterns): ?int
    {
        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            foreach ($patterns as $pattern) {
                if (self::safeMatch($pattern, $sentences[$i])) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * preg_match with a lowered backtrack limit (restored afterwards). A failure (limit hit, bad pattern stored before
     * the checks existed) counts as "no match" and is logged with a code only — never the text.
     */
    private static function safeMatch(string $pattern, string $subject): bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        try {
            $result = @preg_match(self::regex($pattern), $subject);
            if ($result === false) {
                Log::warning('scripts.pattern_limit', ['code' => 'pattern_limit', 'pcre_error' => preg_last_error()]);

                return false;
            }

            return $result === 1;
        } finally {
            ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
        }
    }

    private static function regex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    private static function quote(string $sentence): string
    {
        return mb_strlen($sentence) > self::QUOTE_MAX ? mb_substr($sentence, 0, self::QUOTE_MAX - 1).'…' : $sentence;
    }
}
