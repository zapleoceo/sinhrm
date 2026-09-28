<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Enums\ToolRunner;
use App\Modules\Assistant\Services\InternalApi;

/** Reads any allowed endpoint as the user (in the chat: by the SPA with the user's session). */
final readonly class ApiGetTool implements AssistantTool
{
    public function __construct(private InternalApi $api) {}

    public function name(): string
    {
        return 'api_get';
    }

    public function description(): string
    {
        return 'Read data from the SinHRM API with the rights of the current user (GET). `path` is relative to /api, '
            .'without a leading slash, placeholders filled in (e.g. "candidates/42", "timeoff/balances"); `query` holds '
            .'filters/pagination. Returns {status, data} or {status, error}; 403 means the user has no access — say so, '
            .'do not retry. Large answers are truncated: narrow the query instead of paging blindly.';
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
