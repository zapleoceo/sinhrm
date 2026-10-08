<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\DTO\AiAudio;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Models\AiRequest;

/**
 * The only way other modules reach AI (CLAUDE.md rule 7). Implemented ONLY by Ai\Services\AiService — the gates
 * (global switch, provider, purpose), daily caps, deferral + ai.poll and "no prompt logging" live there; another
 * implementation would bypass them. Docs: docs/modules/ai.md.
 */
interface AiGateway
{
    /** Longest synchronous wait for an answer (serverless limit 60 s minus the rest of the request). */
    public const int WAIT_SECONDS = 40;

    /** null = AI can run for this purpose; otherwise the refusal code (ai_disabled | ai_not_configured | ai_purpose_disabled). */
    public function unavailableReason(AiPurpose $purpose): ?string;

    public function available(AiPurpose $purpose): bool;

    /** @throws AiException ai_disabled | ai_not_configured | ai_purpose_disabled */
    public function assertAvailable(AiPurpose $purpose): void;

    /**
     * @param  array<string, int|string|bool>  $meta  ids/flags the handler needs later (never personal data)
     *
     * @throws AiException when AI is unavailable for the purpose or the daily cap is reached (nothing is sent then)
     */
    public function run(AiPrompt $prompt, ?string $subjectType = null, ?int $subjectId = null, array $meta = [], int $waitSeconds = self::WAIT_SECONDS): AiOutcome;

    /**
     * Speech → text for a conversational purpose, same gates and caps as run().
     *
     * @throws AiException when AI is unavailable for the purpose or the daily cap is reached (nothing is sent then)
     */
    public function transcribe(AiPurpose $purpose, AiAudio $audio, ?string $subjectType = null, ?int $subjectId = null, int $waitSeconds = self::WAIT_SECONDS): AiOutcome;

    /** One poll of a pending request; finished requests are returned as they are. */
    public function refresh(AiRequest $request): AiOutcome;
}
