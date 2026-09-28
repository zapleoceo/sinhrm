<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Ai;

use App\Modules\Ai\Contracts\AiConversationHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiRequest;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Dictation in the «Стік» chat: the transcript goes back to the SPA's input box (the user edits and sends it
 * himself) — nothing is applied to domain data. Finished transcripts stay in the cache for TTL seconds so a deferred
 * one (slow Whisper fallback, finished by polling or ai.poll) can still be collected by its owner.
 */
final readonly class AssistantVoiceHandler implements AiConversationHandler
{
    public const int TTL = 900;

    public function __construct(private Cache $cache) {}

    public static function cacheKey(int $requestId): string
    {
        return 'assistant.voice.'.$requestId;
    }

    public function purpose(): AiPurpose
    {
        return AiPurpose::AssistantVoice;
    }

    /** @return array{text: string} */
    public function parse(array $json, AiRequest $request): array
    {
        return ['text' => trim(is_string($json['text'] ?? null) ? $json['text'] : '')];
    }

    public function apply(AiRequest $request, array $data): void
    {
        $this->cache->put(self::cacheKey($request->id), $data, self::TTL);
    }

    public function failed(AiRequest $request, string $error): void {}

    /** The audio is not kept: nothing to rebuild. */
    public function rebuild(AiRequest $request): ?AiPrompt
    {
        return null;
    }
}
