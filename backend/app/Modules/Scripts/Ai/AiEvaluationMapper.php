<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Ai;

use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Scripts\DTO\EvaluationResult;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\EvaluationEngine;
use App\Modules\Scripts\Support\ScriptScore;

/**
 * Model answer (script_eval.v3, short keys) → validated data (internal names) → EvaluationResult. The score is computed here from the script
 * weights (ScriptScore), never taken from the model; unknown step/objection ids are ignored, missing steps count as
 * not done. Recommendations: the same codes as the rules engine (missed required step, next step not fixed) plus the
 * model's Ukrainian tips as type "ai_tip".
 */
final class AiEvaluationMapper
{
    private const int QUOTE_MAX = 300;

    private const int COMMENT_MAX = 300;

    /** script_eval.v4: 0-2 tips (quality review: 0-5 varied between runs for the same talk). */
    public const int TIPS_MAX = 2;

    /**
     * @param  array<string, mixed>  $json
     * @return array{steps: array<string, array{done: bool, quote: string|null, comment: string|null}>, objections_handled: list<string>, next_step_fixed: bool, next_step_quote: string|null, recommendations: list<string>}
     *
     * @throws InvalidAiOutput
     */
    public static function parse(array $json): array
    {
        $steps = [];
        foreach (JsonOutput::objects($json, 'steps') as $step) {
            $id = JsonOutput::text($step, 'id', 64);
            if ($id === null) {
                throw InvalidAiOutput::because('step_without_id');
            }
            $done = JsonOutput::bool($step, 'done');
            $steps[$id] = [
                'done' => $done,
                'quote' => $done ? JsonOutput::text($step, 'quote', self::QUOTE_MAX) : null,
                'comment' => JsonOutput::text($step, 'note', self::COMMENT_MAX),
            ];
        }

        return [
            'steps' => $steps,
            'objections_handled' => JsonOutput::strings($json, 'handled', 50, 64),
            'next_step_fixed' => JsonOutput::bool($json, 'next'),
            'next_step_quote' => JsonOutput::text($json, 'next_quote', self::QUOTE_MAX),
            'recommendations' => JsonOutput::strings($json, 'tips', self::TIPS_MAX, 400),
        ];
    }

    /** @param  array<string, mixed>  $data  output of parse() (possibly after a JSON round trip) */
    public static function toResult(ScriptContent $script, array $data, ?string $sentText = null): EvaluationResult
    {
        // A quote that is not a literal substring of what the model saw is dropped (no paraphrases or typos as evidence).
        $verbatim = static fn (mixed $q): ?string => is_string($q) && ($sentText === null || str_contains(self::norm($sentText), self::norm($q))) ? $q : null;
        /** @var array<string, array{done?: bool, quote?: string|null, comment?: string|null}> $given */
        $given = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $steps = [];
        $recommendations = [];
        foreach ($script->steps as $step) {
            $answer = $given[$step->id] ?? [];
            $done = ($answer['done'] ?? false) === true;
            $steps[] = [
                'id' => $step->id,
                'title' => $step->title,
                'required' => $step->required,
                'weight' => $step->weight,
                'done' => $done,
                'quote' => $done ? $verbatim($answer['quote'] ?? null) : null,
                'comment' => $answer['comment'] ?? null,
            ];
            if (! $done && $step->required) {
                $recommendations[] = ['type' => 'missed_step', 'step_id' => $step->id, 'title' => $step->title];
            }
        }
        $fixed = ($data['next_step_fixed'] ?? false) === true;
        if (! $fixed) {
            $recommendations[] = ['type' => 'next_step_not_fixed'];
        }
        foreach ((array) ($data['recommendations'] ?? []) as $tip) {
            if (is_string($tip) && $tip !== '') {
                $recommendations[] = ['type' => 'ai_tip', 'text' => $tip];
            }
        }
        $handled = array_map('strval', (array) ($data['objections_handled'] ?? []));
        $objections = array_map(static fn (array $o): array => [
            'id' => $o['id'],
            'trigger' => $o['trigger'],
            'raised' => in_array($o['id'], $handled, true),
            'quote' => null,
            'handled' => in_array($o['id'], $handled, true),
        ], $script->objections);
        $quote = $data['next_step_quote'] ?? null;

        return new EvaluationResult(
            engine: EvaluationEngine::Ai,
            score: ScriptScore::compute($steps),
            steps: $steps,
            nextStep: ['fixed' => $fixed, 'quote' => $verbatim($quote), 'negative_quote' => null],
            objections: $objections,
            recommendations: $recommendations,
        );
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
