<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\Exceptions\InvalidAiOutput;

/**
 * Native tools through strict JSON, for when the broker has no live tool-capable provider (native tools run only on
 * openai/anthropic/gemini/mistral — broker tool_contract.TOOL_PROVIDERS). wrap() turns a conversation prompt with
 * tools into a plain JSON-schema prompt that ANY provider can answer: the tool catalog goes into the (stable, cached)
 * system message, the history's tool calls/results become ordinary messages. unwrap() turns the JSON answer back into
 * native-looking tool calls, so the purpose handler and the caller never see the difference.
 * Setting: ai_broker "native_tools" (off by default = emulate). Flag in ai_requests.meta: tool_emulation.
 */
final class ToolEmulation
{
    public const string META_FLAG = 'tool_emulation';

    private const string PROTOCOL = <<<'TXT'

TOOLS (call them through the JSON answer; you never see their code)
%s

ANSWER FORMAT
Reply with ONE JSON object only: {"say": "<text for the user, may be empty>", "calls": [{"name": "<tool>", "arguments": "<JSON object as a string>"}]}.
- To use tools: put the calls in "calls" (usually "say" empty); their results come back in the next message as TOOL RESULT.
- Final answer: "calls": [] and the text in "say".
- "arguments" must be a JSON object encoded as a string, matching the tool's parameters.
TXT;

    /** A conversation prompt with tools → JSON-schema prompt without tools (unchanged when it has no tools). */
    public static function wrap(AiPrompt $prompt): AiPrompt
    {
        if ($prompt->tools === null || $prompt->history === null) {
            return $prompt;
        }
        $catalog = [];
        $names = [];
        foreach ($prompt->tools as $tool) {
            $fn = is_array($tool['function'] ?? null) ? $tool['function'] : [];
            $names[] = (string) ($fn['name'] ?? '');
            $catalog[] = ['name' => $fn['name'] ?? '', 'description' => $fn['description'] ?? '', 'parameters' => $fn['parameters'] ?? (object) []];
        }
        $system = $prompt->system.sprintf(self::PROTOCOL, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return new AiPrompt(
            $prompt->purpose, $prompt->version.'+json', $system, '', $prompt->maxTokens, $prompt->temperature,
            self::schema($names), 'turn', $prompt->capability, self::history($prompt->history), null,
        );
    }

    /**
     * The JSON answer → text + tool calls with fresh ids (the result of a native-tools answer passes through).
     *
     * @throws InvalidAiOutput when the answer is not the protocol's JSON
     */
    public static function unwrap(AiResult $result): AiResult
    {
        if (! $result->isDone() || $result->toolCalls !== []) {
            return $result;
        }
        $json = JsonOutput::decode((string) $result->text);
        if (! is_array($json) || ! is_string($json['say'] ?? null) || ! is_array($json['calls'] ?? null)) {
            throw InvalidAiOutput::because('not_tool_json');
        }
        $calls = [];
        foreach ($json['calls'] as $i => $call) {
            if (! is_array($call) || ! is_string($call['name'] ?? null)) {
                throw InvalidAiOutput::because('bad_tool_call');
            }
            $arguments = $call['arguments'] ?? '{}';
            $calls[] = [
                'id' => 'call_'.substr(hash('sha256', $call['name'].$i.microtime()), 0, 12),
                'name' => $call['name'],
                'arguments' => is_string($arguments) ? $arguments : (string) json_encode($arguments, JSON_UNESCAPED_UNICODE),
            ];
        }

        return AiResult::done($json['say'], $result->model, $result->tokensIn, $result->tokensOut, $result->tokensCached, $result->costUsd, $result->finishReason, $calls);
    }

    /**
     * Native history → plain messages: an assistant tool call becomes its JSON answer, a tool result a user message.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    private static function history(array $history): array
    {
        $names = [];
        $out = [];
        foreach ($history as $m) {
            $role = $m['role'] ?? '';
            if ($role === 'assistant' && is_array($m['tool_calls'] ?? null) && $m['tool_calls'] !== []) {
                $calls = [];
                foreach ($m['tool_calls'] as $c) {
                    $name = (string) ($c['function']['name'] ?? '');
                    $names[(string) ($c['id'] ?? '')] = $name;
                    $calls[] = ['name' => $name, 'arguments' => (string) ($c['function']['arguments'] ?? '{}')];
                }
                $out[] = ['role' => 'assistant', 'content' => (string) json_encode(['say' => (string) ($m['content'] ?? ''), 'calls' => $calls], JSON_UNESCAPED_UNICODE)];
            } elseif ($role === 'tool') {
                $id = (string) ($m['tool_call_id'] ?? '');
                $out[] = ['role' => 'user', 'content' => 'TOOL RESULT '.($names[$id] ?? 'tool').': '.(string) ($m['content'] ?? '')];
            } elseif ($role === 'assistant') {
                $out[] = ['role' => 'assistant', 'content' => (string) json_encode(['say' => (string) ($m['content'] ?? ''), 'calls' => []], JSON_UNESCAPED_UNICODE)];
            } else {
                $out[] = ['role' => (string) $role, 'content' => (string) ($m['content'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private static function schema(array $names): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'say' => ['type' => 'string'],
                'calls' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string', 'enum' => $names], 'arguments' => ['type' => 'string']],
                    'required' => ['name', 'arguments'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['say', 'calls'],
            'additionalProperties' => false,
        ];
    }
}
