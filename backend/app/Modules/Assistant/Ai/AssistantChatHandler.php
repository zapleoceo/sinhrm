<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Ai;

use App\Modules\Ai\Contracts\AiConversationHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Assistant\Support\ToolRegistry;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Validates one turn of «Стік»: every tool call must name a registered tool and carry a JSON-object argument string
 * (the broker validates too; this is the application's own fail-closed check). apply() keeps the turn in the cache
 * for TTL seconds so a turn finished by the ai.poll job can still be picked up by GET /api/assistant/turns/{id}.
 * Nothing is written to domain data here — tools run only after this, as the user.
 */
final readonly class AssistantChatHandler implements AiConversationHandler
{
    public const int TTL = 900;

    public const int MAX_CALLS = 8;

    public function __construct(
        private ToolRegistry $tools,
        private Cache $cache,
    ) {}

    public static function cacheKey(int $requestId): string
    {
        return 'assistant.turn.'.$requestId;
    }

    public function purpose(): AiPurpose
    {
        return AiPurpose::AssistantChat;
    }

    /** @return array{text: string, tool_calls: list<array{id: string, name: string, arguments: string, args: array<string, mixed>}>} */
    public function parse(array $json, AiRequest $request): array
    {
        $text = is_string($json['text'] ?? null) ? $json['text'] : '';
        $raw = is_array($json['tool_calls'] ?? null) ? $json['tool_calls'] : [];
        if (count($raw) > self::MAX_CALLS) {
            throw InvalidAiOutput::because('too_many_tool_calls');
        }
        $calls = [];
        foreach ($raw as $call) {
            if (! is_array($call) || ! is_string($call['id'] ?? null) || ! is_string($call['name'] ?? null) || ! is_string($call['arguments'] ?? null)) {
                throw InvalidAiOutput::because('bad_tool_call');
            }
            if ($this->tools->find($call['name']) === null) {
                throw InvalidAiOutput::because('unknown_tool');
            }
            $args = json_decode($call['arguments'] === '' ? '{}' : $call['arguments'], true);
            if (! is_array($args) || ($args !== [] && array_is_list($args))) {
                throw InvalidAiOutput::because('bad_tool_arguments');
            }
            /** @var array<string, mixed> $args */
            $calls[] = ['id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments'], 'args' => $args];
        }
        if ($calls === [] && trim($text) === '') {
            throw InvalidAiOutput::because('empty_turn');
        }

        return ['text' => $text, 'tool_calls' => $calls];
    }

    public function apply(AiRequest $request, array $data): void
    {
        $this->cache->put(self::cacheKey($request->id), $data, self::TTL);
    }

    public function failed(AiRequest $request, string $error): void {}

    /** The conversation is not stored: an invalid deferred turn cannot be retried → failed; the user just asks again. */
    public function rebuild(AiRequest $request): ?AiPrompt
    {
        return null;
    }
}
