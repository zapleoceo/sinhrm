<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Enums\ToolRunner;
use App\Modules\Assistant\Services\InternalApi;

/**
 * Changes data through the real API as the user. In the chat the SPA shows a confirmation card with `summary` and
 * runs the request only after the user's click; over MCP the client's own approval step applies (destructive hint).
 */
final readonly class ApiWriteTool implements AssistantTool
{
    public const array METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private InternalApi $api) {}

    public function name(): string
    {
        return 'api_write';
    }

    public function description(): string
    {
        return 'Create, change or delete data in SinHRM (POST/PUT/PATCH/DELETE) with the rights of the current user. '
            .'Check the endpoint and its fields with find_endpoints first. The user must approve every call: write a '
            .'short, concrete `summary` in the user\'s language of exactly what will happen (who/what, old → new). '
            .'If the result says declined, acknowledge and do not retry. 422 = validation errors: fix the body or ask.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'method' => ['type' => 'string', 'enum' => self::METHODS],
                'path' => ['type' => 'string', 'description' => 'Path under /api, e.g. "candidates/42/move"'],
                'body' => ['type' => 'object', 'description' => 'JSON body', 'additionalProperties' => true],
                'summary' => ['type' => 'string', 'description' => 'Human description for the confirmation, user\'s language'],
            ],
            'required' => ['method', 'path', 'summary'],
            'additionalProperties' => false,
        ];
    }

    public function runner(): ToolRunner
    {
        return ToolRunner::Client;
    }

    public function mutating(): bool
    {
        return true;
    }

    public function overMcp(): bool
    {
        return true;
    }

    public function run(User $user, array $args): array
    {
        $method = is_string($args['method'] ?? null) ? strtoupper($args['method']) : '';
        if (! in_array($method, self::METHODS, true)) {
            return ['status' => 422, 'error' => 'invalid_method'];
        }
        $body = is_array($args['body'] ?? null) ? $args['body'] : [];

        /** @var array<string, mixed> $body */
        return $this->api->call($user, $method, is_string($args['path'] ?? null) ? $args['path'] : '', $body);
    }
}
