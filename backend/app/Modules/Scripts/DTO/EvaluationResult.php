<?php

declare(strict_types=1);

namespace App\Modules\Scripts\DTO;

use App\Modules\Scripts\Enums\EvaluationEngine;

/**
 * Outcome of checking a text (call transcript / chat message) against a script version.
 * Recommendations are codes, not sentences: the UI translates them (uk/ru/en).
 *
 * @phpstan-type StepResult array{id: string, title: string, required: bool, weight: int, done: bool, quote: string|null}
 * @phpstan-type NextStep array{fixed: bool, quote: string|null, negative_quote: string|null}
 * @phpstan-type ObjectionResult array{id: string, trigger: string, raised: bool, quote: string|null}
 * @phpstan-type Recommendation array{type: string, step_id?: string, title?: string, quote?: string}
 */
final readonly class EvaluationResult
{
    /**
     * @param  list<StepResult>  $steps
     * @param  NextStep  $nextStep
     * @param  list<ObjectionResult>  $objections
     * @param  list<Recommendation>  $recommendations
     */
    public function __construct(
        public EvaluationEngine $engine,
        public int $score,
        public array $steps,
        public array $nextStep,
        public array $objections,
        public array $recommendations,
    ) {}

    /**
     * Stored in script_evaluations.result (score and engine have their own columns).
     *
     * @return array{steps: list<StepResult>, next_step: NextStep, objections: list<ObjectionResult>, recommendations: list<Recommendation>} */
    public function result(): array
    {
        return [
            'steps' => $this->steps,
            'next_step' => $this->nextStep,
            'objections' => $this->objections,
            'recommendations' => $this->recommendations,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['engine' => $this->engine->value, 'score' => $this->score] + $this->result();
    }
}
