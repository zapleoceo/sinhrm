<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\DTO\AiAudio;
use App\Modules\Ai\DTO\AiJobRef;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\Exceptions\AiException;

/**
 * Speech → text gateway (AI Broker /v1/transcribe/jobs: Whisper chain local → groq → gemini → openai). Used only
 * through AiService::transcribe (same gates, caps and ai_requests bookkeeping as chat). Same rules as AiProvider:
 * never log the audio, the transcript or the key; transport problems become error codes.
 */
interface AiTranscriber
{
    public function key(): string;

    /** @throws AiException code ai_provider_* */
    public function submitAudio(AiAudio $audio, string $workflow): AiJobRef;

    /** Done → AiResult::done with the transcript as text. */
    public function poll(AiJobRef $job): AiResult;
}
