<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Contracts\PersonalTokenRepository;
use App\Modules\Auth\DTO\PersonalTokenStatus;
use App\Modules\Auth\DTO\TokenKind;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Issue / status / revoke of a scoped personal token (extension "clipper", assistant "mcp"): one active token per
 * kind and user — issuing a new one revokes the previous; the plaintext is returned once. Where a token is accepted
 * is decided by TokenScopes, not here.
 */
final readonly class PersonalTokens
{
    public function __construct(
        private PersonalTokenRepository $tokens,
        private LoggerInterface $log,
    ) {}

    public function issue(User $user, TokenKind $kind): PersonalTokenStatus
    {
        $revoked = $this->tokens->deleteAll($user, $kind->name);
        $new = $this->tokens->create($user, $kind->name, [$kind->ability], Carbon::now()->addDays($kind->ttlDays));
        $this->log->info($kind->logEvent.'_issued', ['user' => $user->id, 'revoked' => $revoked]);
        $token = $new->accessToken;

        return new PersonalTokenStatus(true, $token->created_at, null, $token->expires_at, $new->plainTextToken);
    }

    public function status(User $user, TokenKind $kind): PersonalTokenStatus
    {
        $token = $this->tokens->find($user, $kind->name);
        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return new PersonalTokenStatus(false, $token?->created_at, $token?->last_used_at, $token?->expires_at);
        }

        return new PersonalTokenStatus(true, $token->created_at, $token->last_used_at, $token->expires_at);
    }

    public function revoke(User $user, TokenKind $kind): void
    {
        $revoked = $this->tokens->deleteAll($user, $kind->name);
        $this->log->info($kind->logEvent.'_revoked', ['user' => $user->id, 'revoked' => $revoked]);
    }
}
