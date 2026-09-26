<?php

declare(strict_types=1);

namespace App\Modules\Ai\Prompts;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Support\AiPromptRegistry;

/**
 * "Спробувати" in the prompt editor: parses the answer with the code-owned parser of the purpose being edited
 * (meta.purpose) and applies nothing — the admin sees the parsed result, no domain data changes.
 */
final readonly class PromptTrialHandler implements AiResultHandler
{
    public function __construct(private AiPromptRegistry $prompts) {}

    public function purpose(): AiPurpose
    {
        return AiPurpose::PromptTrial;
    }

    public function parse(array $json, AiRequest $request): array
    {
        $purpose = AiPurpose::tryFrom((string) ($request->meta['purpose'] ?? ''));
        $template = $purpose === null ? null : $this->prompts->find($purpose);
        if ($template === null) {
            throw InvalidAiOutput::because('unknown_trial_purpose');
        }

        return $template->parse((string) json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    public function apply(AiRequest $request, array $data): void {}

    public function failed(AiRequest $request, string $error): void {}

    /** The draft text is not stored: a deferred trial is not retried. */
    public function rebuild(AiRequest $request): ?AiPrompt
    {
        return null;
    }
}
