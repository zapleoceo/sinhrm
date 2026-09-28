<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Enums\AiRequestStatus;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Services\AiService;
use App\Modules\Assistant\Ai\AssistantChatHandler;
use App\Modules\Assistant\Ai\AssistantPrompt;
use App\Modules\Assistant\Enums\ToolRunner;
use App\Modules\Assistant\Support\ToolRegistry;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One turn of the in-app chat with «Стік». The server is stateless — the SPA owns the history and drives the loop:
 * each POST /api/assistant/turn = exactly ONE model call through AiService (gates, budget, logging rules), so every
 * request fits the serverless limit. Tool calls of the answer are split:
 *   - ToolRunner::Server (find_endpoints) run here at once → server_results;
 *   - ToolRunner::Client (api_get, api_write, open_page) → client_calls, executed by the SPA with the user's own
 *     session (writes only after the user's click), whose results come back in the next turn's history.
 * A slow answer → state "pending" + request_id; GET /api/assistant/turns/{id} finishes it (only for its owner).
 */
final readonly class AssistantChatService
{
    public const string SUBJECT = 'assistant_user';

    /** Seconds to wait for the model inside one HTTP request (the rest of the 60 s is for tools and the answer). */
    public const int WAIT_SECONDS = 25;

    /** Tool results of the latest round the model has not answered yet. */
    public const int FRESH_TOOL_CHARS = 4000;

    /** Tool results of earlier rounds (already used by the model). */
    public const int OLD_TOOL_CHARS = 300;

    public function __construct(
        private AiService $ai,
        private AiRequestRepository $requests,
        private ToolRegistry $tools,
        private Cache $cache,
    ) {}

    /** @return array{available: bool, reason: string|null} */
    public function availability(): array
    {
        $reason = $this->ai->unavailableReason(AiPurpose::AssistantChat);

        return ['available' => $reason === null, 'reason' => $reason];
    }

    /**
     * @param  list<array<string, mixed>>  $messages  validated history (TurnRequest)
     * @param  array{path: string, title: string}  $page
     * @return array<string, mixed> TurnResult
     */
    public function turn(User $user, array $messages, array $page): array
    {
        $prompt = AssistantPrompt::build(self::compact($messages), $this->tools->definitions(), self::context($user, $page));
        try {
            $outcome = $this->ai->run($prompt, self::SUBJECT, $user->id, [], self::WAIT_SECONDS);
        } catch (AiException $e) {
            return ['state' => 'failed', 'request_id' => 0, 'error' => $e->errorCode];
        }

        return $this->result($user, $outcome);
    }

    /**
     * A pending turn of this user: finishes it if the model is done by now. null = not this user's turn.
     *
     * @return array<string, mixed>|null TurnResult
     */
    public function poll(User $user, int $requestId): ?array
    {
        $request = $this->requests->find($requestId);
        if ($request === null || $request->purpose !== AiPurpose::AssistantChat || $request->subject_type !== self::SUBJECT || $request->subject_id !== $user->id) {
            return null;
        }
        if ($request->status === AiRequestStatus::Pending) {
            return $this->result($user, $this->ai->refresh($request));
        }
        if ($request->status === AiRequestStatus::Failed) {
            return ['state' => 'failed', 'request_id' => $request->id, 'error' => $request->error ?? 'ai_provider_error', ...$this->detail($request->id)];
        }
        $data = $this->cache->get(AssistantChatHandler::cacheKey($request->id));

        return is_array($data)
            ? $this->result($user, AiOutcome::done($request->id, $data))
            : ['state' => 'failed', 'request_id' => $request->id, 'error' => 'ai_timeout'];
    }

    /** @return array<string, mixed> */
    private function result(User $user, AiOutcome $outcome): array
    {
        if ($outcome->isDeferred()) {
            return ['state' => 'pending', 'request_id' => $outcome->requestId];
        }
        if (! $outcome->isDone() || $outcome->data === null) {
            return ['state' => 'failed', 'request_id' => $outcome->requestId, 'error' => $outcome->error ?? 'ai_provider_error', ...$this->detail($outcome->requestId)];
        }
        /** @var array{text: string, tool_calls: list<array{id: string, name: string, arguments: string, args: array<string, mixed>}>} $data */
        $data = $outcome->data;

        $assistant = ['role' => 'assistant', 'content' => $data['text'] === '' ? null : $data['text']];
        if ($data['tool_calls'] !== []) {
            $assistant['tool_calls'] = array_map(static fn (array $c): array => [
                'id' => $c['id'],
                'type' => 'function',
                'function' => ['name' => $c['name'], 'arguments' => $c['arguments']],
            ], $data['tool_calls']);
        }

        $serverResults = [];
        $clientCalls = [];
        foreach ($data['tool_calls'] as $call) {
            $tool = $this->tools->find($call['name']);
            if ($tool !== null && $tool->runner() === ToolRunner::Server) {
                try {
                    $content = $tool->run($user, $call['args']);
                } catch (Throwable $e) {
                    report($e);
                    $content = ['error' => 'tool_failed'];
                }
                $serverResults[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => (string) json_encode($content, JSON_UNESCAPED_UNICODE)];
            } else {
                $clientCalls[] = ['id' => $call['id'], 'name' => $call['name'], 'arguments' => (object) $call['args']];
            }
        }

        return [
            'state' => 'done',
            'request_id' => $outcome->requestId,
            'assistant' => $assistant,
            'server_results' => $serverResults,
            'client_calls' => $clientCalls,
        ];
    }

    /**
     * Keeps the prompt small enough for the free lanes (prod 28.09: a turn with ~30 000 chars of tool results stalled
     * in the broker): results of the latest tool round are cut to FRESH_TOOL_CHARS, older ones to OLD_TOOL_CHARS —
     * the model already used them, the SPA still shows the full answers.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private static function compact(array $messages): array
    {
        $lastAssistant = -1;
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? null) === 'assistant') {
                $lastAssistant = $i;
            }
        }
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? null) !== 'tool' || ! is_string($m['content'] ?? null)) {
                continue;
            }
            $limit = $i > $lastAssistant ? self::FRESH_TOOL_CHARS : self::OLD_TOOL_CHARS;
            if (mb_strlen($m['content']) > $limit) {
                $messages[$i]['content'] = mb_substr($m['content'], 0, $limit).'…[trimmed]';
            }
        }

        return $messages;
    }

    /**
     * Why a turn failed, as codes (invalid_reason / finish_reason from ai_requests.meta) — for diagnosis, no text.
     *
     * @return array{detail?: array<string, int|string|bool>}
     */
    private function detail(int $requestId): array
    {
        $meta = $requestId > 0 ? $this->requests->find($requestId)?->meta : null;
        $detail = array_intersect_key($meta ?? [], array_flip(['invalid_reason', 'finish_reason']));

        return $detail === [] ? [] : ['detail' => $detail];
    }

    /** @param  array{path: string, title: string}  $page */
    private static function context(User $user, array $page): string
    {
        $roles = implode(', ', $user->getRoleNames()->all()) ?: 'employee';
        $today = Carbon::now()->format('Y-m-d (l)');
        // Client-supplied page data must not be able to close or fake the context marker.
        $clean = static fn (string $s, int $max): string => mb_substr((string) preg_replace('~[\[\]\r\n\t«»]+~u', ' ', $s), 0, $max);

        return "user {$user->name}, roles: {$roles}; today {$today}; current page {$clean($page['path'], 200)} «{$clean($page['title'], 120)}»";
    }
}
