<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Services\AiService;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Scripts\Ai\AiEvaluationMapper;
use App\Modules\Scripts\Ai\ScriptEvaluationAiHandler;
use App\Modules\Scripts\Ai\ScriptEvaluationPrompt;
use App\Modules\Scripts\Contracts\ScriptEvaluator;
use App\Modules\Scripts\DTO\EvaluationResult;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\EvaluationEngine;
use App\Modules\Scripts\Exceptions\ScriptException;
use App\Modules\Scripts\Models\ScriptVersion;

/**
 * AI evaluation through the Ai module (prompt script_eval.v1, docs/modules/ai.md). Gate, caps, polling and the
 * one retry on invalid JSON are AiService's; the score is computed from the script weights (AiEvaluationMapper).
 * Refusals and failures surface as ScriptException so EvaluationService falls back to the rules.
 */
final readonly class AiScriptEvaluator implements ScriptEvaluator
{
    public function __construct(private AiService $ai) {}

    public function engine(): EvaluationEngine
    {
        return EvaluationEngine::Ai;
    }

    public function available(): bool
    {
        return $this->ai->available(AiPurpose::ScriptEvaluation);
    }

    /** Text without a stored touch (the editor's "test on text"): waits for the answer, nothing is stored. */
    public function evaluate(ScriptContent $script, string $text): EvaluationResult
    {
        $outcome = $this->run(fn (): AiOutcome => $this->ai->run(ScriptEvaluationPrompt::build($script, $text)));
        if (! $outcome->isDone() || $outcome->data === null) {
            throw ScriptException::aiUnavailable($outcome->error ?? 'ai_pending');
        }

        return AiEvaluationMapper::toResult($script, $outcome->data, ScriptEvaluationPrompt::sentText($text));
    }

    /**
     * Evaluates a stored touch: done → the handler has stored the AI evaluation; deferred → ai.poll stores it later.
     *
     * @throws ScriptException when AI refuses (off, not configured, over the cap)
     */
    public function evaluateTouch(ScriptVersion $version, Touchpoint $touch, int $waitSeconds): AiOutcome
    {
        return $this->run(fn (): AiOutcome => $this->ai->run(
            ScriptEvaluationPrompt::build($version->content(), (string) $touch->body),
            ScriptEvaluationAiHandler::SUBJECT,
            $touch->id,
            ['script_version_id' => $version->id],
            $waitSeconds,
        ));
    }

    /** @param  callable(): AiOutcome  $call */
    private function run(callable $call): AiOutcome
    {
        try {
            return $call();
        } catch (AiException $e) {
            throw ScriptException::aiUnavailable($e->errorCode);
        }
    }
}
