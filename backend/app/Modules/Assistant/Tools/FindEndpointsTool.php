<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Enums\ToolRunner;
use App\Modules\Assistant\Support\EndpointCatalog;

/** Searches the live API catalog (EndpointCatalog) — the helper's map of the whole system. */
final readonly class FindEndpointsTool implements AssistantTool
{
    public function __construct(private EndpointCatalog $catalog) {}

    public function name(): string
    {
        return 'find_endpoints';
    }

    public function description(): string
    {
        return 'Find SinHRM API endpoints the current user may call. Use BEFORE api_get / api_write whenever you are not '
            .'sure about a path or its fields. `query` = a few English keywords (e.g. "candidate stage move", '
            .'"time off balance", "vacancy list"); optional `module` narrows to one module key. Empty query and no '
            .'module returns the list of modules with endpoint counts. Paths contain {placeholders} to fill in.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'English keywords'],
                'module' => ['type' => 'string', 'description' => 'Optional module key, e.g. recruiting, people, timeoff'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function runner(): ToolRunner
    {
        return ToolRunner::Server;
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
        $query = is_string($args['query'] ?? null) ? mb_substr($args['query'], 0, 200) : '';
        $module = is_string($args['module'] ?? null) ? $args['module'] : null;

        return $this->catalog->search($user, $query, $module);
    }
}
