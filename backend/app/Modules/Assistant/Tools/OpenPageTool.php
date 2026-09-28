<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Enums\ToolRunner;

/** Opens a page of the SPA for the user (browser only; not offered over MCP). */
final readonly class OpenPageTool implements AssistantTool
{
    public function name(): string
    {
        return 'open_page';
    }

    public function description(): string
    {
        return 'Open a page of the SinHRM web app for the user, e.g. "/candidates/42" or "/timeoff". Use when the user '
            .'asks to go somewhere or when showing the page helps more than words. Only paths from the app map.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'SPA path starting with "/"'],
                'reason' => ['type' => 'string', 'description' => 'Why (shown to the user), user\'s language'],
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
        return false;
    }

    public function run(User $user, array $args): array
    {
        $path = is_string($args['path'] ?? null) ? $args['path'] : '';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return ['error' => 'path must start with a single "/"'];
        }

        return ['url' => rtrim((string) config('app.frontend_url'), '/').$path];
    }
}
