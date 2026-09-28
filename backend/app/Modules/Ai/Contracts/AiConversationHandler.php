<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Handler of a conversational purpose (free text + native tool calls, no strict JSON answer). AiService skips the
 * JSON decoding and hands parse() the raw turn instead:
 *   ['text' => string, 'tool_calls' => list<array{id: string, name: string, arguments: string}>]
 * parse() validates the calls (known names, JSON-object arguments) and throws InvalidAiOutput otherwise.
 */
interface AiConversationHandler extends AiResultHandler {}
