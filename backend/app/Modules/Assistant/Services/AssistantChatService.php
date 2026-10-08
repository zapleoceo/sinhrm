<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Enums\AiRequestStatus;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Assistant\Ai\AssistantChatHandler;
use App\Modules\Assistant\Ai\AssistantPrompt;
use App\Modules\Assistant\Enums\ToolRunner;
use App\Modules\Assistant\Support\AssistantDataPolicy;
use App\Modules\Assistant\Support\ToolRegistry;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One turn of the in-app chat with «Стік». The server is stateless — the SPA owns the history and drives the loop:
 * each POST /api/assistant/turn = exactly ONE model call through AiService (gates, budget, logging rules), so every
 * request fits the serverless limit. Tool calls of the answer are split:
 *   - ToolRunner::Server (find_endpoints) run here at once → server_results;
 *   - ToolRunner::Client (api_get, api_write, open_page) → client_calls, executed by the SPA with the user's own
 *     session (writes only after the user's click); AssistantHistory refetches allowed reads for the next turn.
 * A slow answer → state "pending" + request_id; GET /api/assistant/turns/{id} finishes it (only for its owner).
 */
final readonly class AssistantChatService
{
    public const string SUBJECT = 'assistant_user';

    /** Seconds to wait for the model inside one HTTP request (the rest of the 60 s is for tools and the answer). */
    public const int WAIT_SECONDS = 25;

    public function __construct(
        private AiGateway $ai,
        private AiRequestRepository $requests,
        private ToolRegistry $tools,
        private Cache $cache,
        private AssistantHistory $history,
        private AssistantScope $scope,
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
        try {
            // No API replay when policy/provider/purpose has switched AI off. AiService still applies its gates.
            $this->ai->assertAvailable(AiPurpose::AssistantChat);
            $scope = $this->scope->capture($user);
            if ($scope === null) {
                return self::contextChanged(0);
            }
            $history = $this->history->build($scope['actor'], $messages);
            if ($history === []) {
                return ['state' => 'failed', 'request_id' => 0, 'error' => 'ai_invalid_output'];
            }
            $actor = $this->scope->matchingActor($user, $scope['fingerprint']);
            if ($actor === null) {
                return self::contextChanged(0);
            }
            $prompt = AssistantPrompt::build($history, $this->tools->definitions(), self::context($actor, $page));
            $outcome = $this->ai->run($prompt, self::SUBJECT, $user->id, [], self::WAIT_SECONDS);
        } catch (AiException $e) {
            return ['state' => 'failed', 'request_id' => 0, 'error' => $e->errorCode];
        }

        return $this->deliver($user, $outcome, $scope['fingerprint'], true);
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
        $fingerprint = $this->cache->get(AssistantScope::cacheKey($requestId));
        if (! is_string($fingerprint) || $this->scope->matchingActor($user, $fingerprint) === null) {
            return self::contextChanged($requestId);
        }
        if ($request->status === AiRequestStatus::Pending) {
            return $this->deliver($user, $this->ai->refresh($request), $fingerprint);
        }
        if ($request->status === AiRequestStatus::Failed) {
            return ['state' => 'failed', 'request_id' => $request->id, 'error' => $request->error ?? 'ai_provider_error', ...$this->detail($request->id)];
        }
        $data = $this->cache->get(AssistantChatHandler::cacheKey($request->id));

        return is_array($data)
            ? $this->deliver($user, AiOutcome::done($request->id, $data), $fingerprint)
            : ['state' => 'failed', 'request_id' => $request->id, 'error' => 'ai_timeout'];
    }

    /**
     * No model answer or server/client tool is delivered under authority that changed while awaiting the broker.
     *
     * @return array<string, mixed>
     */
    private function deliver(User $user, AiOutcome $outcome, string $fingerprint, bool $bindScope = false): array
    {
        $actor = $this->scope->matchingActor($user, $fingerprint);
        if ($actor === null) {
            return self::contextChanged($outcome->requestId);
        }
        if ($bindScope && $outcome->requestId > 0) {
            $this->cache->put(AssistantScope::cacheKey($outcome->requestId), $fingerprint, AssistantChatHandler::TTL);
        }

        return $this->result($actor, $outcome);
    }

    /** @return array{state: string, request_id: int, error: string} */
    private static function contextChanged(int $requestId): array
    {
        return ['state' => 'failed', 'request_id' => $requestId, 'error' => 'ai_context_changed'];
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
                    Log::warning('assistant.server_tool_failed', ['exception' => $e::class]);
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
        $roles = implode(', ', array_intersect($user->getRoleNames()->all(), UserRole::values())) ?: 'employee';
        $today = Carbon::now()->format('Y-m-d (l)');
        $path = AssistantDataPolicy::pagePath($page['path']);

        return "roles: {$roles}; today {$today}; current page {$path}";
    }
}
