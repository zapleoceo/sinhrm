<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Mcp;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * An AssistantTool offered over MCP (laravel/mcp): same name, description and JSON schema as in the in-app chat,
 * annotated read-only / destructive, executed on the server as the token's user (InternalApi → real endpoints).
 */
final class BridgedTool extends Tool
{
    public function __construct(private readonly AssistantTool $tool) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function title(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('unauthenticated');
        }
        $result = $this->tool->run($user, $request->all());
        $status = $result['status'] ?? 200;

        return is_int($status) && $status >= 400
            ? Response::error((string) json_encode($result, JSON_UNESCAPED_UNICODE))
            : Response::json($result);
    }

    public function toArray(): array
    {
        $mutating = $this->tool->mutating();

        return [
            ...parent::toArray(),
            'inputSchema' => $this->tool->parameters(),
            'annotations' => [
                'readOnlyHint' => ! $mutating,
                'destructiveHint' => $mutating,
                'idempotentHint' => ! $mutating,
                'openWorldHint' => false,
            ],
        ];
    }
}
