<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Mcp;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolRegistry;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;

/**
 * MCP server of SinHRM at POST /api/mcp (Streamable HTTP, laravel/mcp): the helper's tools for external AI clients
 * (Claude Desktop / Claude Code / any MCP client). Auth: the user's personal "mcp" token (Bearer), issued in the
 * helper's settings; every call acts with that user's rights. Docs: docs/modules/assistant.md.
 */
final class SinhrmMcpServer extends Server
{
    protected string $name = 'SinHRM';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        SinHRM — recruiting & HR platform. Tools act with the rights of the token owner.
        1. find_endpoints: search the API map with English keywords (empty query → list of modules).
        2. api_get: read data (path relative to /api, e.g. "candidates/42").
        3. api_write: create/change/delete; explain to the user what will change before calling.
        Facts only from tool results; 403 = no access (do not work around it).
    MARKDOWN;

    protected function boot(): void
    {
        $registry = Container::getInstance()->make(ToolRegistry::class);
        $this->tools = array_values(array_map(
            static fn (AssistantTool $tool): BridgedTool => new BridgedTool($tool),
            array_filter($registry->all(), static fn (AssistantTool $tool): bool => $tool->overMcp()),
        ));
    }
}
