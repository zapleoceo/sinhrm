<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Auth\DTO\PersonalTokenStatus;
use App\Modules\Auth\DTO\TokenKind;
use App\Modules\Auth\Services\PersonalTokens;

/**
 * Personal MCP token of a user (connect Claude Desktop / Claude Code to SinHRM): ability "mcp" only, 90 days, one per
 * user; Sanctum accepts it on /api/mcp only (TokenScopes, AssistantServiceProvider::boot).
 */
final readonly class McpTokenService
{
    public const string NAME = 'mcp';

    public const string ABILITY = 'mcp';

    public const int TTL_DAYS = 90;

    public function __construct(private PersonalTokens $tokens) {}

    public static function kind(): TokenKind
    {
        return new TokenKind(self::NAME, self::ABILITY, self::TTL_DAYS, 'assistant.mcp_token');
    }

    public function issue(User $user): PersonalTokenStatus
    {
        return $this->tokens->issue($user, self::kind());
    }

    public function status(User $user): PersonalTokenStatus
    {
        return $this->tokens->status($user, self::kind());
    }

    public function revoke(User $user): void
    {
        $this->tokens->revoke($user, self::kind());
    }
}
