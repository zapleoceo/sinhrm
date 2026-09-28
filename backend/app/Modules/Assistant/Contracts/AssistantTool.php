<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use App\Models\User;
use App\Modules\Assistant\Enums\ToolRunner;

/**
 * One capability of the helper «Стік», described once and offered both to the in-app chat (as a native LLM tool)
 * and to external AI clients over MCP (/api/mcp). Tools are tagged with AssistantServiceProvider::TOOLS_TAG, so any
 * module can add its own. A tool never grants anything: it acts with the rights of the user it runs for.
 */
interface AssistantTool
{
    /** snake_case, unique, stable (the model and MCP clients call it by this name). */
    public function name(): string;

    /** What the tool does and when to use it (English: it is read by the model). */
    public function description(): string;

    /** @return array<string, mixed> JSON schema (type object) of the arguments */
    public function parameters(): array;

    /** Where the in-app chat runs it: on the server, or in the browser (the user's own session, confirmations). */
    public function runner(): ToolRunner;

    /** Changes data (MCP destructive hint; the chat asks the user to confirm first). */
    public function mutating(): bool;

    /** Offered over MCP (browser-only tools such as opening a page are not). */
    public function overMcp(): bool;

    /**
     * Server-side execution: chat tools with ToolRunner::Server and every MCP call.
     *
     * @param  array<string, mixed>  $args  already decoded; validate before use
     * @return array<string, mixed> JSON-serializable result for the model
     */
    public function run(User $user, array $args): array;
}
