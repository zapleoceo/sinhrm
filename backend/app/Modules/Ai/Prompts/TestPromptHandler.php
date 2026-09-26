<?php

declare(strict_types=1);

namespace App\Modules\Ai\Prompts;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiRequest;

/** The test prompt has nothing to apply: the admin sees the outcome of the request. */
final class TestPromptHandler implements AiResultHandler
{
    public function purpose(): AiPurpose
    {
        return AiPurpose::Test;
    }

    public function parse(array $json, AiRequest $request): array
    {
        return TestPrompt::parseJson($json);
    }

    public function apply(AiRequest $request, array $data): void {}

    public function failed(AiRequest $request, string $error): void {}

    public function rebuild(AiRequest $request): AiPrompt
    {
        return TestPrompt::build();
    }
}
