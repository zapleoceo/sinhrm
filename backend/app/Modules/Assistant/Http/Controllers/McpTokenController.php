<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Models\User;
use App\Modules\Assistant\Services\McpTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /api/assistant/mcp-token — the user's own MCP token (session routes; an MCP token cannot reach them). */
final readonly class McpTokenController
{
    public function __construct(private McpTokenService $tokens) {}

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->tokens->status(self::actor($request))->toArray()]);
    }

    /** The plaintext token is in this response only; issuing revokes the previous token. */
    public function issue(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->tokens->issue(self::actor($request))->toArray()], 201);
    }

    public function revoke(Request $request): Response
    {
        $this->tokens->revoke(self::actor($request));

        return response()->noContent();
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
