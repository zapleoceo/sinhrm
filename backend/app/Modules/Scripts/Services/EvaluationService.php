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

    public function forTouchpoint(Touchpoint $touchpoint): ScriptEvaluation
    {
        return $this->evaluations->findByTouchpoint($touchpoint->id) ?? throw ScriptException::notEvaluated();
    }

    /**
     * @param  list<int>  $touchpointIds
     * @return array<int, array<string, mixed>>
     */
    public function summaries(array $touchpointIds): array
    {
        return array_map(
            static fn (ScriptEvaluation $e): array => $e->summary(),
            $this->evaluations->forTouchpoints($touchpointIds),
        );
    }
}
