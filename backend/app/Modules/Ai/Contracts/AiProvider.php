<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\DTO\AiJobRef;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\Exceptions\AiException;

/**
 * An LLM gateway. Implementations must never log prompts, answers or the key, and must turn every transport problem
 * into an error CODE (AiException / AiResult::error), never into the provider's message text.
 * Default binding: AiBrokerProvider (AiServiceProvider); OpenRouterProvider is the alternative.
 */
interface AiProvider
{
    /** Integration key whose settings/secrets the provider uses ("ai_broker", "openrouter"). */
    public function key(): string;

    /** @throws AiException code ai_provider_* */
    public function submit(AiPrompt $prompt): AiJobRef;

    /** Pure read of the job state; never throws for transport problems (returns AiResult::error). */
    public function poll(AiJobRef $job): AiResult;
}
