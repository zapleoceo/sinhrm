<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Ai;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Scripts\Contracts\EvaluationRepository;
use App\Modules\Scripts\Contracts\ScriptRepository;

/**
 * Applies an AI evaluation of a touch (subject "touchpoint", meta script_version_id): stores it, or upgrades the rules
 * evaluation stored while the answer was pending (same script version only; an AI evaluation is never overwritten).
 * On failure nothing changes — the rules evaluation stays.
 */
final readonly class ScriptEvaluationAiHandler implements AiResultHandler
{
    public const string SUBJECT = 'touchpoint';

    public function __construct(
        private ScriptRepository $scripts,
        private EvaluationRepository $evaluations,
        private TouchpointRepository $touchpoints,
    ) {}

    public function purpose(): AiPurpose
    {
        return AiPurpose::ScriptEvaluation;
    }

    public function parse(array $json, AiRequest $request): array
    {
        return AiEvaluationMapper::parse($json);
    }

    public function apply(AiRequest $request, array $data): void
    {
        $versionId = $request->metaInt('script_version_id');
        if ($request->subject_type !== self::SUBJECT || $request->subject_id === null || $versionId === null) {
            return;
        }
        $version = $this->scripts->findVersionById($versionId);
        if ($version === null) {
            return;
        }
        $touch = $this->touchpoints->find($request->subject_id);
        $result = AiEvaluationMapper::toResult($version->content(), $data, ScriptEvaluationPrompt::sentText((string) $touch?->body));
        $this->evaluations->storeAi($request->subject_id, $version->id, [
            'score' => $result->score,
            'result' => $result->result(),
            'prompt_version' => $request->prompt_version,
            'ai_request_id' => $request->id,
        ]);
    }

    public function failed(AiRequest $request, string $error): void
    {
        // The rules evaluation (stored when the AI answer was not ready) stays as it is.
    }

    public function rebuild(AiRequest $request): ?AiPrompt
    {
        $versionId = $request->metaInt('script_version_id');
        $touch = $request->subject_id === null ? null : $this->touchpoints->find($request->subject_id);
        $version = $versionId === null ? null : $this->scripts->findVersionById($versionId);
        if ($touch === null || $version === null || $touch->body === null) {
            return null;
        }

        return ScriptEvaluationPrompt::build($version->content(), $touch->body);
    }
}
