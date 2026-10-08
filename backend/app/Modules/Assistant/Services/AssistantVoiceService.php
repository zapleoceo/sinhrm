<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiAudio;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Enums\AiRequestStatus;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Assistant\Ai\AssistantVoiceHandler;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Voice dictation for the chat: audio → AiGateway::transcribe (Whisper via the broker, same switches and caps as the
 * chat) → text for the input box. Slow transcripts come back as "pending"; only their owner can collect them.
 * The audio is sent once and never stored by SinHRM (the broker drops it when the job ends).
 */
final readonly class AssistantVoiceService
{
    public const string SUBJECT = 'assistant_voice_user';

    public const int WAIT_SECONDS = 25;

    public function __construct(
        private AiGateway $ai,
        private AiRequestRepository $requests,
        private Cache $cache,
    ) {}

    /** @return array{state: string, request_id: int, text?: string, error?: string} */
    public function transcribe(User $user, AiAudio $audio): array
    {
        try {
            $outcome = $this->ai->transcribe(AiPurpose::AssistantVoice, $audio, self::SUBJECT, $user->id, self::WAIT_SECONDS);
        } catch (AiException $e) {
            return ['state' => 'failed', 'request_id' => 0, 'error' => $e->errorCode];
        }

        return self::result($outcome);
    }

    /** @return array{state: string, request_id: int, text?: string, error?: string}|null null = not this user's request */
    public function poll(User $user, int $requestId): ?array
    {
        $request = $this->requests->find($requestId);
        if ($request === null || $request->purpose !== AiPurpose::AssistantVoice || $request->subject_type !== self::SUBJECT || $request->subject_id !== $user->id) {
            return null;
        }
        if ($request->status === AiRequestStatus::Pending) {
            return self::result($this->ai->refresh($request));
        }
        if ($request->status === AiRequestStatus::Failed) {
            return ['state' => 'failed', 'request_id' => $request->id, 'error' => $request->error ?? 'ai_provider_error'];
        }
        $data = $this->cache->get(AssistantVoiceHandler::cacheKey($request->id));

        return is_array($data)
            ? self::result(AiOutcome::done($request->id, $data))
            : ['state' => 'failed', 'request_id' => $request->id, 'error' => 'ai_timeout'];
    }

    /** @return array{state: string, request_id: int, text?: string, error?: string} */
    private static function result(AiOutcome $outcome): array
    {
        if ($outcome->isDeferred()) {
            return ['state' => 'pending', 'request_id' => $outcome->requestId];
        }
        $text = $outcome->isDone() && is_string($outcome->data['text'] ?? null) ? $outcome->data['text'] : null;
        if ($text === null) {
            return ['state' => 'failed', 'request_id' => $outcome->requestId, 'error' => $outcome->error ?? 'ai_provider_error'];
        }

        return $text === ''
            ? ['state' => 'failed', 'request_id' => $outcome->requestId, 'error' => 'empty_transcript']
            : ['state' => 'done', 'request_id' => $outcome->requestId, 'text' => $text];
    }
}
