<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Models\User;
use App\Modules\Assistant\Http\Requests\TranscribeRequest;
use App\Modules\Assistant\Http\Requests\TurnRequest;
use App\Modules\Assistant\Services\AssistantChatService;
use App\Modules\Assistant\Services\AssistantVoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/assistant/* — the in-app chat with «Стік» (session auth, every user of an enabled module). */
final readonly class AssistantController
{
    public function __construct(
        private AssistantChatService $chat,
        private AssistantVoiceService $voice,
    ) {}

    /** Whether the chat works now (AI switched on) and the MCP address for external clients. */
    public function status(): JsonResponse
    {
        return new JsonResponse(['data' => [
            ...$this->chat->availability(),
            'mcp_url' => rtrim((string) config('app.frontend_url'), '/').'/api/mcp',
        ]]);
    }

    /** One model turn: {state: done|pending|failed, request_id, assistant?, server_results?, client_calls?, error?}. */
    public function turn(TurnRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->chat->turn(self::actor($request), $request->history(), $request->page())]);
    }

    /** A pending turn of the current user (404 for anyone else's). */
    public function poll(Request $request, int $requestId): JsonResponse
    {
        $result = $this->chat->poll(self::actor($request), $requestId);

        return $result === null ? new JsonResponse(['message' => 'Not found.'], 404) : new JsonResponse(['data' => $result]);
    }

    /** Voice dictation: {state: done|pending|failed, request_id, text?, error?}; the SPA puts the text into the input. */
    public function transcribe(TranscribeRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->voice->transcribe(self::actor($request), $request->audio())]);
    }

    /** A pending transcript of the current user (404 for anyone else's). */
    public function transcription(Request $request, int $requestId): JsonResponse
    {
        $result = $this->voice->poll(self::actor($request), $requestId);

        return $result === null ? new JsonResponse(['message' => 'Not found.'], 404) : new JsonResponse(['data' => $result]);
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
