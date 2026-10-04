<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Assistant\Support\AssistantDataPolicy;
use App\Modules\Assistant\Support\EndpointCatalog;
use App\Modules\Assistant\Support\ToolRegistry;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The browser owns the display history, not trusted model evidence. Rebuild only the latest completed tool round
 * as the currently authenticated actor. Never replay writes or forward browser tool bodies/assistant prose.
 */
final readonly class AssistantHistory
{
    public const int MAX_REPLAYS = 8;

    public const int MAX_RESULT_CHARS = 4000;

    private const array TOOLS = ['api_get', 'api_write', 'find_endpoints', 'open_page'];

    public function __construct(
        private InternalApi $api,
        private ToolRegistry $tools,
        private EndpointCatalog $catalog,
        private ModuleRegistry $modules,
    ) {}

    /**
     * @param  list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function build(User $user, array $messages): array
    {
        $lastAssistant = -1;
        $ids = [];
        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }
            $lastAssistant = $i;
            foreach ($message['tool_calls'] ?? [] as $call) {
                if (is_string($call['id'] ?? null)) {
                    $ids[$call['id']] = ($ids[$call['id']] ?? 0) + 1;
                }
            }
        }
        $fresh = ($messages[array_key_last($messages)]['role'] ?? null) === 'tool' ? $lastAssistant : -1;
        $out = [];
        $sequence = 0;
        $replays = 0;
        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) === 'user') {
                // Deliberate user input remains intentional model input; this is not universal text redaction.
                $out[] = ['role' => 'user', 'content' => $message['content']];
                continue;
            }
            if (($message['role'] ?? null) !== 'assistant' || ! is_array($message['tool_calls'] ?? null)) {
                continue;
            }
            $results = [];
            for ($j = $i + 1; $j < count($messages) && ($messages[$j]['role'] ?? null) === 'tool'; $j++) {
                $id = $messages[$j]['tool_call_id'] ?? null;
                if (is_string($id)) {
                    $results[$id][] = $messages[$j];
                }
            }
            $calls = [];
            $safeResults = [];
            foreach ($message['tool_calls'] as $call) {
                $id = $call['id'] ?? null;
                $name = $call['function']['name'] ?? null;
                if (! is_string($id) || $id === '' || ($ids[$id] ?? 0) !== 1 || count($results[$id] ?? []) !== 1
                    || ! in_array($name, self::TOOLS, true) || $this->tools->find($name) === null) {
                    continue;
                }
                $raw = $call['function']['arguments'] ?? null;
                $args = is_string($raw) ? json_decode($raw, true) : null;
                $valid = is_array($args) && ($args === [] || ! array_is_list($args));
                $args = $valid ? $args : [];
                $safeId = 'history_'.++$sequence;
                $calls[] = ['id' => $safeId, 'type' => 'function', 'function' => [
                    'name' => $name,
                    'arguments' => (string) json_encode((object) $this->arguments($user, $name, $args), JSON_UNESCAPED_UNICODE),
                ]];
                $content = ['omitted' => 'older_tool_result'];
                if ($i === $fresh && $replays++ < self::MAX_REPLAYS) {
                    $content = $valid ? $this->fresh($user, $name, $args, $results[$id][0]) : ['error' => 'invalid_tool_arguments'];
                }
                $encoded = (string) json_encode($content, JSON_UNESCAPED_UNICODE);
                // Only server-projected data or code-owned metadata reaches this size boundary.
                $safeResults[] = ['role' => 'tool', 'tool_call_id' => $safeId, 'content' => mb_strlen($encoded) > self::MAX_RESULT_CHARS
                    ? mb_substr($encoded, 0, self::MAX_RESULT_CHARS).'…[trimmed]' : $encoded];
            }
            if ($calls !== []) {
                $out[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls];
                array_push($out, ...$safeResults);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, string>
     */
    private function arguments(User $user, string $name, array $args): array
    {
        $path = is_string($args['path'] ?? null) ? $args['path'] : '';
        if ($name === 'api_get') {
            $safe = AssistantDataPolicy::readPath($path);

            return $safe === null ? [] : ['path' => $safe];
        }
        if ($name === 'api_write') {
            $method = is_string($args['method'] ?? null) ? strtoupper($args['method']) : '';
            $safe = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $this->catalog->promptPath($user, $method, $path) : null;

            return $safe === null ? [] : ['method' => $method, 'path' => $safe];
        }
        if ($name === 'find_endpoints') {
            $module = $args['module'] ?? null;

            return is_string($module) && $this->modules->find($module) !== null ? ['module' => $module] : [];
        }

        return ['path' => AssistantDataPolicy::pagePath($path)];
    }

    /**
     * @param  array<string, mixed> $args
     * @param  array<string, mixed> $clientResult
     * @return array<string, mixed>
     */
    private function fresh(User $user, string $name, array $args, array $clientResult): array
    {
        if ($name === 'api_write') {
            $client = json_decode(is_string($clientResult['content'] ?? null) ? $clientResult['content'] : '', true);

            return ['unverified' => true, 'ack' => is_array($client) && ($client['declined'] ?? false) === true ? 'write_declined' : 'write_result_omitted'];
        }
        if ($name === 'open_page') {
            return ['unverified' => true, 'ack' => 'navigation_result_omitted'];
        }
        try {
            if ($name === 'api_get') {
                return $this->api->call($user, 'GET', is_string($args['path'] ?? null) ? $args['path'] : '', is_array($args['query'] ?? null) ? $args['query'] : []);
            }

            return $this->tools->find('find_endpoints')?->run($user, $args) ?? ['error' => 'tool_unavailable'];
        } catch (Throwable $e) {
            Log::warning('assistant.history_failed', ['exception' => $e::class]);

            return ['error' => 'tool_failed'];
        }
    }
}
