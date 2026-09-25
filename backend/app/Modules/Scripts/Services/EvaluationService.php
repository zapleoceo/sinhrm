<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Modules\Integrations\Contracts\AiPolicy;
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
 * Picks the engine and stores evaluations. AI is tried only when the global switch is on; any refusal of the AI
 * evaluator (switched off, not configured) falls back to the rules, so an evaluation is always produced.
 */
final readonly class EvaluationService
{
    /** Missing evaluations computed synchronously per timeline request (lazy fallback). */
    public const int LAZY_LIMIT = 5;

    public function __construct(
        private AiPolicy $policy,
        private AiScriptEvaluator $ai,
        /** The rules engine (bound in ScriptsServiceProvider). */
        private ScriptEvaluator $rules,
        private ScriptRepository $scripts,
        private EvaluationRepository $evaluations,
        private TouchpointRepository $touchpoints,
    ) {}

    public function evaluate(ScriptContent $script, string $text): EvaluationResult
    {
        if ($this->policy->enabled()) {
            try {
                return $this->ai->evaluate($script, $text);
            } catch (ScriptException) {
                // AI switched on but not wired yet: fall through to the rules.
            }
        }

        return $this->rules->evaluate($script, $text);
    }

    /**
     * Evaluates a touch against the active script of its channel (call transcript / long outbound chat message) and
     * stores the result once. null = the touch is not evaluated (wrong kind, no active script).
     */
    public function evaluateTouchpoint(int $touchpointId): ?ScriptEvaluation
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
        $result = $this->evaluate($version->content(), (string) $touch->body);

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
