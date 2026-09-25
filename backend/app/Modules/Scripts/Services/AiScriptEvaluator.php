<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\Scripts\Contracts\ScriptEvaluator;
use App\Modules\Scripts\DTO\EvaluationResult;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\EvaluationEngine;
use App\Modules\Scripts\Exceptions\ScriptException;

/**
 * Placeholder for the AI evaluation (docs/modules/scripts.md). It NEVER calls an AI provider:
 * - AI switched off (the default, AiPolicy::enabled() = false) → ScriptException::aiDisabled();
 * - AI switched on → ScriptException::aiNotConfigured(): models and the prompt are not approved by the owner yet.
 * EvaluationService falls back to the rules evaluator in both cases. When the prompt is approved, the provider call
 * goes here (stable part = the script version → prompt caching; the conversation goes last).
 */
final readonly class AiScriptEvaluator implements ScriptEvaluator
{
    public function __construct(private AiPolicy $policy) {}

    public function engine(): EvaluationEngine
    {
        return EvaluationEngine::Ai;
    }

    public function evaluate(ScriptContent $script, string $text): EvaluationResult
    {
        if (! $this->policy->enabled()) {
            throw ScriptException::aiDisabled();
        }

        throw ScriptException::aiNotConfigured();
    }
}
