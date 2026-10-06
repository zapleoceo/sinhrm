<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Enums\ToolRunner;
use App\Modules\Assistant\Services\InternalApi;

/** Reads as the user; automatic model/MCP results additionally pass the server privacy whitelist. */
final readonly class ApiGetTool implements AssistantTool
{
    public function __construct(private InternalApi $api) {}

    public function name(): string
    {
        return 'api_get';
    }

    public function description(): string
    {
        return 'Read permitted integer record/reference IDs and numeric pagination counters as the current user (GET). '
            .'Allowed: auth/me; people list/numeric details, me/employee, people/search and people/lookup; '
            .'candidates, vacancies and tasks lists/numeric details; pipelines list. No names, contacts, salaries, '
            .'dates, free text, statuses or URLs. Other endpoints are unavailable, even to admins. `path` is relative '
            .'to /api (e.g. "candidates/42"); `query` holds filters/pagination used locally and omitted from model history. '
            .'Returns {status, data} or {status, error}; 403 means unavailable or no access: explain and do not retry. '
            .'Projection precedes truncation; prefer narrow queries and perPage=1 for counts.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path under /api, e.g. "vacancies/3"'],
                'query' => ['type' => 'object', 'description' => 'Query parameters', 'additionalProperties' => true],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function runner(): ToolRunner
    {
        return ToolRunner::Client;
    }

    public function mutating(): bool
    {
        return false;
    }

    public function overMcp(): bool
    {
        return true;
    }

    public function run(User $user, array $args): array
    {
        $query = is_array($args['query'] ?? null) ? $args['query'] : [];

        /** @var array<string, mixed> $query */
        return $this->api->call($user, 'GET', is_string($args['path'] ?? null) ? $args['path'] : '', $query);
    }
}
