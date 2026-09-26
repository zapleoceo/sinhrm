<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Scripts\Contracts\EvaluationRepository;
use App\Modules\Scripts\Contracts\ScriptEvaluator;
use App\Modules\Scripts\Contracts\ScriptRepository;
use App\Modules\Scripts\DTO\EvaluationResult;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Exceptions\ScriptException;
use App\Modules\Scripts\Models\ScriptEvaluation;

/**
 * Picks the engine and stores evaluations. AI is tried when it is available for script evaluation (global switch,
 * provider configured, purpose on); any refusal or failure (over the cap, provider error, invalid answer) falls back
 * to the rules, so an evaluation is always produced. A touch whose AI answer is not ready within the wait gets the
 * rules evaluation now; the ai.poll job later replaces it with the AI one (ScriptEvaluationAiHandler).
 */
final readonly class EvaluationService
{
    /** Missing evaluations computed synchronously per timeline request (lazy fallback). */
    public const int LAZY_LIMIT = 5;

    public function __construct(
        private AiScriptEvaluator $ai,
        /** The rules engine (bound in ScriptsServiceProvider). */
        private ScriptEvaluator $rules,
        private ScriptRepository $scripts,
        private EvaluationRepository $evaluations,
        private TouchpointRepository $touchpoints,
    ) {}

    public function evaluate(ScriptContent $script, string $text): EvaluationResult
    {
        if ($this->ai->available()) {
            try {
                return $this->ai->evaluate($script, $text);
            } catch (ScriptException) {
                // AI refused, failed or is still thinking: the rules answer now.
            }
        }

        return $this->rules->evaluate($script, $text);
    }

    /**
     * Evaluates a touch against the active script of its channel (call transcript / long outbound chat message) and
     * stores the result once. null = the touch is not evaluated (wrong kind, no active script).
     * $aiWaitSeconds: how long to wait for the AI answer (0 = submit only; timeline requests must stay fast).
     */
    public function evaluateTouchpoint(int $touchpointId, int $aiWaitSeconds = 0): ?ScriptEvaluation
    {
        $touch = $this->touchpoints->find($touchpointId);
        if ($touch === null) {
            return null;
        }
        $channel = ScriptChannel::forTouch($touch->channel, $touch->direction, $touch->body);
        if ($channel === null) {
            return null;
        }
        $existing = $this->evaluations->findByTouchpoint($touch->id);
        if ($existing !== null) {
            return $existing;
        }
        $version = $this->scripts->activeFor($channel)?->activeVersion;
        if ($version === null) {
            return null;
        }
        if ($this->ai->available()) {
            try {
                if ($this->ai->evaluateTouch($version, $touch, $aiWaitSeconds)->isDone()) {
                    $stored = $this->evaluations->findByTouchpoint($touch->id);
                    if ($stored !== null) {
                        return $stored;
                    }
                }
            } catch (ScriptException) {
                // Over the cap or switched off meanwhile: rules below.
            }
        }
        $result = $this->rules->evaluate($version->content(), (string) $touch->body);

        return $this->evaluations->createOnce($touch->id, [
            'script_version_id' => $version->id,
            'engine' => $result->engine->value,
            'score' => $result->score,
            'result' => $result->result(),
        ]);
    }

    /** Stored evaluation, or evaluated now if the after-response run did not happen (lazy fallback). */
    public function forTouchpoint(Touchpoint $touchpoint): ScriptEvaluation
    {
        $evaluation = $this->evaluations->findByTouchpoint($touchpoint->id) ?? $this->evaluateTouchpoint($touchpoint->id);
        if ($evaluation === null) {
            throw ScriptException::notEvaluated();
        }

        return $evaluation->loadMissing('version.script');
    }

    /**
     * @param  list<int>  $touchpointIds
     * @return array<int, array<string, mixed>>
     */
    public function summaries(array $touchpointIds): array
    {
        $found = $this->evaluations->forTouchpoints($touchpointIds);
        // Lazy fallback for the after-response fast path (it may not run on serverless): evaluate missing eligible
        // touches now, at most LAZY_LIMIT per request so a timeline page stays fast.
        $budget = self::LAZY_LIMIT;
        foreach ($touchpointIds as $id) {
            if ($budget === 0) {
                break;
            }
            if (isset($found[$id])) {
                continue;
            }
            $touch = $this->touchpoints->find($id);
            if ($touch === null || $touch->candidate_id === null || ScriptChannel::forTouch($touch->channel, $touch->direction, $touch->body) === null) {
                continue;
            }
            $budget--;
            $evaluation = $this->evaluateTouchpoint($id);
            if ($evaluation !== null) {
                $found[$id] = $evaluation;
            }
        }

        return array_map(static fn (ScriptEvaluation $e): array => $e->summary(), $found);
    }
}
